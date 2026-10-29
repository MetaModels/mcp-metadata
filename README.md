# MCP Metadata

Facts about the MetaModels packages, their attribute and filter types and the pages of the handbook, for the MCP
server of MetaModels ([metamodels/mcp-admin](https://github.com/MetaModels/mcp-admin)).

## What this does

MetaModels is modular: `metamodels/core` is always there, the attribute and filter types come from further packages.
An AI that talks to the MCP server has to know which package a task needs, also when it is not installed yet, and a
list inside `mcp-admin` would age with every new package. The facts are therefore kept here, apart from the
installations, and `mcp-admin` fetches the published file `metadata.json` (and falls back to the copy that ships with
it when there is no connection).

The file has these parts (format version 1, see `schema/metadata.schema.json`):

- `handbook`: the base language of the handbook (German) and its address per language. A page is named by its path,
  which is the same in every language, so `address + path` is the link.
- `packages`: the Composer packages, with a summary, keywords, the page in the handbook and the availability.
- `types`: the attribute and filter types of a package, with summary, keywords, the page in the handbook and abilities
  that came with a later version (`features`).
- `articles`: pages of the handbook about a topic (`topic`, such as relations or several languages) or a task
  (`recipe` of the cookbook). Only title, path and keywords, the text stays in the handbook.

Everything is data, not instructions: the texts end up in the context of an AI. They are short plain texts without
markup, the links are `https`, and the schema does not allow other fields.

### Availability

| Value | Meaning |
| --- | --- |
| `public` | `composer require` works for everybody. |
| `early-access` | Only for supporters, `access.url` points to a page for humans (never credentials). The AI does not suggest `composer require`. |
| `planned` | Does not exist yet. |
| `unknown` | Not stated yet. The AI suggests `composer require` and says that many extensions are still part of the Early Adopter Program (EAP): if Composer cannot find the package or refuses access, the user has to ask about access. |

### Plans

`plans` names the supporter plans that include an `early-access` package: `basic-1` (the reduced plan for small
projects) and `eap` (the full Early Adopter Program). Both are described on the fundraising page that `access.url`
points to. A package with a fundraising of its own has no `plans`, its `access.url` is the page in the handbook.

## Layout

```
data/handbook.json                         where the handbook is, per language
data/packages/<vendor>/<package>.json      a package with its types
data/articles/<id>.json                    a page of the handbook
schema/metadata.schema.json                the format
bin/build                                  puts the files together and checks them
```

`bin/build` writes `build/metadata.json`, the file that is published. It checks the references between the parts (the
name of a package is its file name, the id of an article is its file name, no type twice, articles are about types that
exist) and the result against the schema.

```
composer install
bin/build --check     # only check
bin/build             # write build/metadata.json
bin/build --urls      # the addresses of the handbook pages, for the link check
```

## Adding a package or a page

1. Add or change the file in `data/` and run `bin/build --check`.
2. Give types a short English `summary` and `keywords` in the languages the users speak (at least German and
   English), the page in the handbook as `docs` (path without the address, without an anchor: anchors differ per
   language).
3. Open a pull request. The checks run the tests, the build and a check of the links; the maintainers review the
   entries.

The handbook has no page of its own for MetaModels 2.6 yet. When it has, add it as an article of the kind `topic`.

Packages that are not freely available register the same way. Do not put access data or private repository addresses
in here.

## Publishing

On every push to `main` the file is built and published with GitHub Pages:
`https://metamodels.github.io/mcp-metadata/metadata.json`. `mcp-admin` takes the address from its option
`meta_models_mcp_admin.metadata.url`.

## Requirements

- PHP 8.4 or later
