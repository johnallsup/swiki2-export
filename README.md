# S-Wiki v2
A simple secure/secret wiki written in HTML and Javascript with an example PHP/Sqlite3 backend.

This is something that was largely vibecoded in Gemini based on an idea I had years ago.
The idea is that `hash(passphrase,path)` essentially always goes to something unique,
assuming no hash collisions. Then `hash(passphrase,path,salt_key)` and `hash(passphrase,path,salt_value)`
give a row id and decryption key for a row in a key value store. This allows for a document store
with many document spaces, whose presence can only be verified with a correct passphrase.
The passphrase *defines* the space it unlocks (rather than having a named space and a separate
passphrase which must be stored). This results in something delightfully simple.

What you see here is largely a vibecoded web app based on this idea.
Essentially if you have Apache+PHP with sqlite3 installed, you can stick this anywhere
you like on the server.

Largely this is a bit of fun with standard crypto primitives.

## Keyboard Shortcuts
(The convention here is A-C-M-S-x for Alt-Control-Meta-Shift-x.)
* A-C-p: change passphrase
* C-k: Jump to page with fuzzy matching
* C-S-k: Create page
* C-s: Save page (requires write permission, will normally update the manifest too, can optionally add chaff)
* C-e: Switch view mode

## Enhancements
It supports Mathjax, using `\(...\)` and `\[...\]` as delimiters. It supports ABC via
````
```abc
L:1/8
C D E F
```
````
fenced code blocks. Tables in a simple CSV format via
````
```csv sep=|
head1|head2
data1|data2
```
````
Source code is formatted via `highlight.js`.

## Source Format
Pages are written in bsaic Markdown. There is a simple built in Markdown renderer that allows arbitrary special
fenced code blocks and `[[pseudolinks:like this]]` to be defined to do things (e.g. rabbit hole links which
jump to different passphrase spaces).

## Images
It cannot *store* images, but can link to them. This is by design to keep things simple.

## Forther Ideas
One could easily write an Electron app to handle the wiki-v2.db files.
All the main functionality is contained in a few classes in `index.html`.
This example is deliberately written as a single largely self-contained `html` file,
with only optional enhancements like ABC and MathJaX and Hightlight.js brought in from a CDN.
