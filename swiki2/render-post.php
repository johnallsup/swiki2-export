<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Error: POST requests only.');
}
$source = $_POST['markdown'] ?? '';
$source = str_replace("\r\n", "\n", $source);
$title = $_POST['title'] ?? 'Rendered Markdown';
?><!DOCTYPE html>
<html>
  <head>
    <meta charset='utf8'/>
    <title><?php echo $title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.12.0/styles/default.min.css">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.12.0/highlight.min.js"></script>
    <script>
      window.MathJax = {
        tex: {
          inlineMath: [["\\(", "\\)"]],
          displayMath: [["\\[", "\\]"]],
          processEscapes: false
        },
        options: {
          skipHtmlTags: ["script", "noscript", "style", "textarea", "pre", "code"]
        },
        startup: {
          typeset: false
        }
      };
    </script>
    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/abcjs@6.7.2/dist/abcjs-basic-min.min.js"></script>
  </head>
  <body>
<div id="source"><?php echo $source; ?></div>
<div id="pane"></div>
  </body>
<script type="module">
   // ========================================================================
    // 0. ExtensibleMarkdownParser (with nested lists, specials & math tokens)
    // ========================================================================
    export class ExtensibleMarkdownParser {
      constructor(options = {}) {
        this.blockSpecials = new Map();
        this.inlineSpecials = new Map();
        this.normalizeWikiLink =
          typeof options.normalizeWikiLink === "function"
            ? options.normalizeWikiLink
            : (target) => {
                const t = target.trim();
                return t.startsWith("/") ? t : "/" + t;
              };

        this._ingestSpecials(this.blockSpecials, options.blockSpecials);
        this._ingestSpecials(this.inlineSpecials, options.inlineSpecials);
      }

      _ingestSpecials(targetMap, source) {
        if (!source) return;
        if (source instanceof Map) {
          for (const [k, fn] of source.entries()) {
            if (typeof fn === "function") {
              targetMap.set(String(k).toLowerCase(), fn);
            }
          }
        } else if (typeof source === "object") {
          for (const [k, fn] of Object.entries(source)) {
            if (typeof fn === "function") {
              targetMap.set(String(k).toLowerCase(), fn);
            }
          }
        }
      }

      registerBlockSpecial(name, func) {
        if (typeof func !== "function") {
          throw new TypeError(`Block special '${name}' handler must be a function.`);
        }
        this.blockSpecials.set(String(name).toLowerCase(), func);
        return this;
      }

      registerInlineSpecial(name, func) {
        if (typeof func !== "function") {
          throw new TypeError(`Inline special '${name}' handler must be a function.`);
        }
        this.inlineSpecials.set(String(name).toLowerCase(), func);
        return this;
      }

      escapeHtml(str) {
        return String(str)
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;");
      }

      _parseFenceHeader(headerStr) {
        const trimmed = headerStr.trim();
        if (!trimmed) return { lang: "", meta: {}, rawMeta: "" };
        const firstSpace = trimmed.search(/\s/);
        const lang = (firstSpace === -1 ? trimmed : trimmed.slice(0, firstSpace)).toLowerCase();
        const rawMeta = firstSpace === -1 ? "" : trimmed.slice(firstSpace + 1).trim();

        const meta = {};
        if (rawMeta) {
          for (const token of rawMeta.split(/\s+/)) {
            const eq = token.indexOf("=");
            if (eq > 0) {
              const key = token.slice(0, eq).toLowerCase();
              let val = token.slice(eq + 1);
              if (
                (val.startsWith('"') && val.endsWith('"')) ||
                (val.startsWith("'") && val.endsWith("'"))
              ) {
                val = val.slice(1, -1);
              }
              if (val === "\\t") val = "\t";
              meta[key] = val;
            } else {
              meta[token.toLowerCase()] = true;
            }
          }
        }

        return { lang, meta, rawMeta };
      }

      _isExternalUrl(href) {
        return /^(?:https?:\/\/|mailto:|tel:|data:|#)/i.test(href.trim());
      }

      _extractMathSpan(text, mathTokens) {
        const stash = (rawMath) => {
          const id = `\u0002MATH${mathTokens.length}\u0002`;
          mathTokens.push(rawMath);
          return id;
        };

        // Block display math: \[ ... \]
        let out = text.replace(/\\\[([\s\S]*?)\\\]/g, (match) => stash(match));
        // Inline math: \( ... \)
        out = out.replace(/\\\(([\s\S]*?)\\\)/g, (match) => stash(match));
        return out;
      }

      _restoreMathSpan(html, mathTokens) {
        return html.replace(/\u0002MATH(\d+)\u0002/g, (_, idx) => mathTokens[Number(idx)]);
      }

      renderInline(text, mathTokens = []) {
        const tokens = [];
        const stash = (html) => {
          const id = `\u0001TOK${tokens.length}\u0001`;
          tokens.push(html);
          return id;
        };

        let work = this._extractMathSpan(String(text), mathTokens);

        // 1. Inline code (`...`)
        work = work.replace(/`([^`\n]+)`/g, (_, code) =>
          stash(`<code>${this.escapeHtml(code)}</code>`)
        );

        // 2. Double-bracket [[name:something]] (inline special) vs [[linkname]] (local wiki link)
        work = work.replace(/\[\[([^\[\]\n]+)\]\]/g, (_, inner) => {
          const colonIdx = inner.indexOf(":");
          if (colonIdx > 0) {
            const specialName = inner.slice(0, colonIdx).trim().toLowerCase();
            const payload = inner.slice(colonIdx + 1);
            const handler = this.inlineSpecials.get(specialName);
            if (handler) {
              return stash(String(handler(payload, this)));
            }
          }
          const target = this.normalizeWikiLink(inner);
          return stash(
            `<a href="#" data-wiki-link="${this.escapeHtml(target)}">${this.escapeHtml(inner.trim())}</a>`
          );
        });

        // 3. Markdown links [label](target): local namespace link vs external URL
        work = work.replace(/\[([^\[\]\n]+)\]\(([^()\s]+)\)/g, (_, label, target) => {
          if (this._isExternalUrl(target)) {
            return stash(
              `<a href="${this.escapeHtml(target)}" target="_blank" rel="noopener noreferrer">${this.escapeHtml(label)}</a>`
            );
          }
          const normTarget = this.normalizeWikiLink(target);
          return stash(
            `<a href="#" data-wiki-link="${this.escapeHtml(normTarget)}">${this.escapeHtml(label)}</a>`
          );
        });

        // Escape remaining plain text (leaving $ untouched), then apply bold / italic
        work = this.escapeHtml(work);
        work = work.replace(/\*\*([^*\n]+)\*\*/g, "<strong>$1</strong>");
        work = work.replace(/(^|[^*])\*([^*\n]+)\*/g, (_, lead, body) => `${lead}<em>${body}</em>`);

        return work.replace(/\u0001TOK(\d+)\u0001/g, (_, idx) => tokens[Number(idx)]);
      }

      _renderNestedList(listItems, mathTokens) {
        if (!listItems.length) return "";

        const makeFrame = (indent, kind) => ({ indent, kind, items: [] });

        const root = makeFrame(listItems[0].indent, listItems[0].kind);
        const stack = [root];

        for (const item of listItems) {
          while (stack.length > 1 && item.indent < stack[stack.length - 1].indent) {
            stack.pop();
          }

          let currentFrame = stack[stack.length - 1];

          if (item.indent > currentFrame.indent) {
            const parentItems = currentFrame.items;
            const hostLi = parentItems.length > 0 ? parentItems[parentItems.length - 1] : null;
            const childFrame = makeFrame(item.indent, item.kind);

            if (hostLi) {
              hostLi.subLists.push(childFrame);
            } else {
              currentFrame.items.push({ text: "", subLists: [childFrame] });
            }

            stack.push(childFrame);
            currentFrame = childFrame;
          } else if (item.kind !== currentFrame.kind) {
            const prevLi = currentFrame.items.length > 0
              ? currentFrame.items[currentFrame.items.length - 1]
              : null;

            if (prevLi) {
              const childFrame = makeFrame(item.indent, item.kind);
              prevLi.subLists.push(childFrame);
              stack.push(childFrame);
              currentFrame = childFrame;
            } else {
              const replacement = makeFrame(item.indent, item.kind);
              stack[stack.length - 1] = replacement;
              currentFrame = replacement;
            }
          }

          currentFrame.items.push({ text: item.text, subLists: [] });
        }

        const renderFrame = (frame) => {
          const tag = frame.kind === "ol" ? "ol" : "ul";
          const lis = frame.items
            .map((li) => {
              const childrenHtml = li.subLists.map(renderFrame).join("");
              return `<li>${this.renderInline(li.text, mathTokens)}${childrenHtml}</li>`;
            })
            .join("");
          return `<${tag}>${lis}</${tag}>`;
        };

        return renderFrame(root);
      }

      parse(markdown) {
        const mathTokens = [];
        // Extract multi-line display math \[...\] before line splitting while keeping fenced blocks intact
        const normalized = String(markdown).replace(/\r\n?/g, "\n");
        const lines = normalized.split("\n");
        const output = [];
        let i = 0;

        while (i < lines.length) {
          const line = lines[i];

          // 1. Fenced code / block-special blocks (```lang meta...)
          const fenceMatch = line.match(/^\s*```(.*)$/);
          if (fenceMatch) {
            const { lang, meta, rawMeta } = this._parseFenceHeader(fenceMatch[1]);
            const contentLines = [];
            i++;
            while (i < lines.length && !/^\s*```\s*$/.test(lines[i])) {
              contentLines.push(lines[i]);
              i++;
            }
            if (i < lines.length) i++;

            const content = contentLines.join("\n");
            const specialFn = lang ? this.blockSpecials.get(lang) : null;

            if (specialFn) {
              output.push(
                String(
                  specialFn(
                    { lang, meta, rawMeta, content, lines: contentLines },
                    this
                  )
                )
              );
            } else {
              const langAttr = lang ? ` class="language-${this.escapeHtml(lang)}"` : "";
              output.push(`<pre><code${langAttr}>${this.escapeHtml(content)}</code></pre>`);
            }
            continue;
          }

          // 2. Multi-line display math block starting with \[
          if (/^\s*\\\[/.test(line)) { const mathLines = [line]; let closed = /\\\]\s*$/.test(line) && !/^\s*\\\[\s*\\\]\s*$/.test(line);
            i++;
            while (!closed && i < lines.length) {
              mathLines.push(lines[i]);
              if (/\\\]/.test(lines[i])) {
                closed = true;
              }
              i++;
            }
            const rawBlock = mathLines.join("\n");
            const tokenId = `\u0002MATH${mathTokens.length}\u0002`;
            mathTokens.push(rawBlock);
            output.push(`<p>${tokenId}</p>`);
            continue;
          }

          // 3. Blank lines
          if (line.trim() === "") {
            i++;
            continue;
          }

          // 4. Headings (# .. ######)
          const headingMatch = line.match(/^(#{1,6})\s+(.*)$/);
          if (headingMatch) {
            const level = headingMatch[1].length;
            output.push(`<h${level}>${this.renderInline(headingMatch[2].trim(), mathTokens)}</h${level}>`);
            i++;
            continue;
          }

          // 5. Blockquotes (> ...)
          if (/^\s*>\s?/.test(line)) {
            const quoteLines = [];
            while (i < lines.length && /^\s*>\s?/.test(lines[i])) {
              quoteLines.push(lines[i].replace(/^\s*>\s?/, ""));
              i++;
            }
            output.push(`<blockquote>${this.parse(quoteLines.join("\n"))}</blockquote>`);
            continue;
          }

          // 6. Nested Lists (unordered -, *, + or ordered 1.)
          const listLineMatch = line.match(/^(\s*)(?:([-*+])|(\d+)\.)\s+(.*)$/);
          if (listLineMatch) {
            const collected = [];
            while (i < lines.length) {
              const m = lines[i].match(/^(\s*)(?:([-*+])|(\d+)\.)\s+(.*)$/);
              if (!m) break;
              collected.push({
                indent: m[1].replace(/\t/g, "  ").length,
                kind: m[3] ? "ol" : "ul",
                text: m[4],
              });
              i++;
            }
            output.push(this._renderNestedList(collected, mathTokens));
            continue;
          }

          // 7. Paragraphs
          const paraLines = [];
          while (
            i < lines.length &&
            lines[i].trim() !== "" &&
            !/^\s*```/.test(lines[i]) &&
            !/^\s*\\\[/.test(lines[i]) &&
            !/^(#{1,6})\s+/.test(lines[i]) &&
            !/^\s*>\s?/.test(lines[i]) &&
            !/^(\s*)(?:([-*+])|(\d+)\.)\s+/.test(lines[i])
          ) {
            paraLines.push(lines[i].trim());
            i++;
          }
          if (paraLines.length > 0) {
            output.push(`<p>${this.renderInline(paraLines.join(" "), mathTokens)}</p>`);
          }
        }

        return this._restoreMathSpan(output.join("\n"), mathTokens);
      }
    }

    export function abcBlockSpecial(block, parser) {
      const content = block.lines.join("\n")
      console.log("abc",content)
      return `<div class="abc-to-render">${content}</div>`
    }

    export function csvBlockSpecial(block, parser) {
      const sep = typeof block.meta?.sep === "string" && block.meta.sep.length > 0 ? block.meta.sep : ",";
      const rawLines = block.lines.filter((l) => l.length > 0);
      if (!rawLines.length) return "<table></table>";

      let hasHeader = false;
      let startIdx = 0;
      const headPrefix = "csvhead:";

      if (rawLines[0].toLowerCase().startsWith(headPrefix)) {
        hasHeader = true;
        startIdx = 1;
      }

      const renderRowCells = (lineStr, tagName) => {
        const cells = lineStr.split(sep);
        return cells
          .map((cell) => `<${tagName}>${parser.renderInline(cell.trim())}</${tagName}>`)
          .join("");
      };

      let html = "<table>";
      if (hasHeader) {
        const headerLine = rawLines[0].slice(headPrefix.length);
        html += `<thead><tr>${renderRowCells(headerLine, "th")}</tr></thead>`;
      }

      const bodyLines = rawLines.slice(startIdx);
      if (bodyLines.length > 0) {
        html += "<tbody>";
        for (const rLine of bodyLines) {
          html += `<tr>${renderRowCells(rLine, "td")}</tr>`;
        }
        html += "</tbody>";
      }

      html += "</table>";
      return html;
    }

   // ========================================================================
    // 3. Parser, Unified Debouncer (highlight.js + MathJax), Copy Buttons & UI
    // ========================================================================
    const mdParser = new ExtensibleMarkdownParser({
      blockSpecials: { csv: csvBlockSpecial, abc: abcBlockSpecial },
      inlineSpecials: {
        flibble: (text, parser) => {
          const safe = parser.escapeHtml(text);
          const width = Math.max(80, safe.length * 9 + 24);
          return `<svg class="swiki-badge" width="${width}" height="24" viewBox="0 0 ${width} 24" xmlns="http://www.w3.org/2000/svg"><rect x="0.5" y="0.5" width="${width - 1}" height="23" rx="4" fill="#22262f" stroke="#7c9cff"/><text x="${width / 2}" y="16" fill="#e4e7ec" font-family="monospace" font-size="12" text-anchor="middle">${safe}</text></svg>`;
        },
      },
    });

   const $ = (id) => document.getElementById(id);

    function hasMathDelimiters(text) {
      return /\\\(|\\\[/.test(text);
    }

    function applySyntaxHighlighting(pane) {
      if (!window.hljs) return;
      const blocks = pane.querySelectorAll("pre code");
      for (const el of blocks) {
        el.removeAttribute("data-highlighted");
        window.hljs.highlightElement(el);
      }
    }

    async function applyMathJax(pane) {
      if (!hasMathDelimiters(pane.innerHTML)) return;
      const mj = window.MathJax;
      if (!mj) return;

      if (typeof mj.typesetClear === "function") {
        mj.typesetClear([pane]);
      }

      if (typeof mj.typesetPromise === "function") {
        try {
          await mj.typesetPromise([pane]);
        } catch {
          // Ignore superseded typeset calls during rapid edits
        }
      } else if (typeof mj.typeset === "function") {
        mj.typeset([pane]);
      }
    }

    function applyAbc(pane) {
      const elements = pane.querySelectorAll('div.abc-to-render');

      elements.forEach((el, index) => {
        const abc = el.textContent;

        // Give each element a unique ID
        const id = `abc-render-${index}`;

        el.id = id;

        ABCJS.renderAbc(id, abc, {
          responsive: 'resize'
        });
      });
    }

    function runEnhancements(pane) {
      applySyntaxHighlighting(pane);
      applyMathJax(pane);
      applyAbc(pane);
    }

   function attachCodeCopyButtons(pane) {
      const preBlocks = pane.querySelectorAll("pre");
      for (const pre of preBlocks) {
        if (pre.querySelector(".code-copy-btn")) continue;
        const codeEl = pre.querySelector("code");
        if (!codeEl) continue;

        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "code-copy-btn";
        btn.textContent = "Copy";
        btn.title = "Copy code to clipboard";

        btn.onclick = async () => {
          try {
            await navigator.clipboard.writeText(codeEl.innerText);
            btn.textContent = "Copied";
            btn.classList.add("copied");
            setTimeout(() => {
              btn.textContent = "Copy";
              btn.classList.remove("copied");
            }, 1500);
          } catch {
            btn.textContent = "Failed";
            setTimeout(() => {
              btn.textContent = "Copy";
            }, 1500);
          }
        };

        pre.appendChild(btn);
      }
    }

    const pane = $("pane");
    const source = $("source");
    const sourceText = source.textContent
    source.remove()

    async function waitForLibraries() {
        await Promise.all([
            waitFor(() => window.MathJax?.typesetPromise),
            waitFor(() => window.ABCJS?.renderAbc),
            waitFor(() => window.hljs?.highlightElement)
        ]);
    }

    function waitFor(test) {
        return new Promise(resolve => {
            const check = () => {
                if (test()) resolve();
                else setTimeout(check, 50);
            };
            check();
        });
    }

    async function render(source,pane) {
      pane.innerHTML = mdParser.parse(source);
      attachCodeCopyButtons(pane);

      await waitForLibraries()
      runEnhancements(pane);
    }

    render(sourceText,pane)

</script>
</html>
