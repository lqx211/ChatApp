# dscview — DeepSeek 聊天记录查看器

A small, self-contained, **client-side** web viewer for exported Deepseek chat records.

Everything runs in the browser: you pick / drag a `.json` export file, it is parsed
locally (streamed, so even a ~160MB export works without freezing), and a chat UI is
rendered. **Nothing is uploaded to any server.**

## Files

- `index.php` — the page (static HTML, no server-side logic).
- `index.css` — styles.
- `main.js` — upload, streaming JSON parse, markdown rendering, chat UI.

## Usage

Open `index.php` through any static server (e.g. `http://localhost/apps/dscview/`),
then choose your exported Deepseek JSON file.

## How it parses

The export is a JSON **array** of chat sessions, e.g. ~160MB / ~2000 chats. Loading it
with a single `JSON.parse` would blow up memory, so `main.js`:

1. streams the file with `File.stream()` + `TextDecoder`,
2. tokenises it character-by-character (respecting string escapes, so `{}`/`[]` inside
   message text don't break the split),
3. `JSON.parse`-s one chat object at a time and flattens its `mapping` tree into an
   ordered message list,
4. keeps only a lightweight list index in memory, with messages stored per chat.

## Data format

`c.json` is a JSON array of chat sessions. Each session:

```json
{
  "id": "<uuid>",
  "title": "…",
  "inserted_at": "…",
  "updated_at": "…",
  "mapping": { "root": {"children": ["1"], "message": null}, "1": {…} }
}
```

`mapping` is a tree; each node carries a `message` with `fragments`. Fragment
types: `REQUEST`, `RESPONSE`, `THINK`, `SEARCH`, `TOOL_OPEN`, `TOOL_SEARCH`, `FILE`.