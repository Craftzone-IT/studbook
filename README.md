# Studbook

A self-hosted web app for cataloguing your brick collection — sets, loose parts and the boxes they live in — and for answering the question **"what can I build from what I already have?"**

> **Status:** early development. Nothing is usable yet. See [docs/milestones.md](docs/milestones.md) for the roadmap.

## Why

Existing tools are great for buying and selling, but cataloguing a real, physical collection is slow:

- adding a set by mistake means deleting its parts one by one;
- finding "a red 2x2 brick" in a huge catalogue is painful;
- nothing knows which physical box a part is sitting in.

Studbook is built around how a collection is actually stored: boxes labelled with part numbers, sets kept whole or taken apart, and a pile of unsorted pieces.

## Planned features

- **Box-based entry** — pick a box (or scan its QR code), see only the parts written on its lid, tap part → colour → quantity. The part stays selected so you only switch colours.
- **Sets as a single unit** — add a set by number, record only the differences (missing/extra parts), remove it in one click. Every change is batched and can be undone.
- **Step-by-step part picker** (category → size → colour) plus free-text search (`3001 red`, `2x2 red brick`).
- **Collections** — keep several independent collections (e.g. yours and your kid's), search across all of them, move items between them.
- **QR labels** — each box gets a printable label whose QR code opens the box's live contents.
- **"What can I build?"** — coverage of any official set from loose parts first, then from sets you allow borrowing from; missing parts exported as a BrickLink wanted-list XML; per-box pick list.
- **Camera features (optional)** — read handwritten box labels and part lists with server-side OCR; identify single parts from a photo via the [Brickognize](https://brickognize.com) API.
- **Bilingual UI** — English (default) and Hungarian, switchable in Settings. More languages welcome.

## Self-hosted, single user

Studbook is meant to run as **your own private instance**: one login, no public registration. It is not designed to be offered as a public service — the catalogue data it uses comes with licence terms that make that a bad idea (see below).

## Catalogue data

This repository contains **code only**. No catalogue data, images or downloads are included. After installing, you fetch the data yourself:

| Source | What it provides | How |
| --- | --- | --- |
| [Rebrickable](https://rebrickable.com/downloads/) | parts, colours, categories, sets, inventories, part relationships | Free CSV downloads, fetched automatically (at most once a day, per Rebrickable's rules) |
| [BrickLink](https://www.bricklink.com/catalogDownload.asp) | BrickLink item numbers and colour IDs | Catalogue download with **your own** free BrickLink account; place the files in the configured folder |

Please respect each provider's terms: do not scrape their websites and do not redistribute their data files.

Part data provided by [Rebrickable](https://rebrickable.com). Part recognition (optional) by [Brickognize](https://brickognize.com).

## Requirements

- PHP 8.2+ with PDO MySQL
- MySQL 8 / MariaDB 10.6+
- A cron job for the weekly catalogue import
- HTTPS (browsers only allow camera access on secure origins)
- Optional: Tesseract OCR on the server for the camera features

Installation instructions will follow with milestone M0.

## Contributing

Issues and pull requests are welcome. All code, comments, commits and docs are in English. UI strings go through the translation system — see [CLAUDE.md](CLAUDE.md) for conventions.

## Licence

[GNU Affero General Public License v3.0](LICENSE). If you run a modified version of Studbook on a server that others can access, you must make your modified source available to them.

## Trademarks

LEGO® is a trademark of the LEGO Group, which does not sponsor, authorize or endorse this project. BrickLink® is a trademark of the LEGO Group. Rebrickable and Brickognize are the property of their respective owners. Studbook is an independent fan project.
