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
- `types`: the attribute and filter types of a package, with summary, keywords, the page in the handbook, abilities
  that came with a later version (`features`) and, for planning, `capabilities` of an attribute and `works_with` of a
  filter (see below).
- `articles`: pages of the handbook about a topic (`topic`, such as relations or several languages) or a task
  (`recipe` of the cookbook). Only title, path and keywords, the text stays in the handbook.

Everything is data, not instructions: the texts end up in the context of an AI. They are short plain texts without
markup, the links are `https`, and the schema does not allow other fields.

### Capabilities and works_with

Facts that decide whether a type fits a task. They are optional, a type without them just says nothing.

`capabilities` of an attribute type:

| Field | Meaning |
| --- | --- |
| `multilingual` | `true` for the `translated*` variants: one value per language. Select and tags follow the language of a multilingual MetaModel by themselves, so they are `false`. The build checks that this matches the name of the type. |
| `multiple` | Can hold several values per item (possibly by a setting, such as several files). |
| `datasources` | Where the values come from: `metamodel`, `table`, `file` or `fixed` (a list such as countries). Left out when the user types the value. |
| `file_usage` | Shows up in the file usage list of Contao. |
| `frontend_editing` | `support` is `yes`, `extra-package` or `no`. With `extra-package`, `requires` names the packages needed in addition (`contao-community-alliance/contao-multicolumnwizard-frontend-bundle` for attributes that work with the MultiColumnWizard, `contao-community-alliance/contao-textfield-multiple-bundle` for attributes with several inputs). |

`works_with` of a filter type names the attribute types the rule can be applied to (`attribute_types`, each has to be
an attribute type of this file) and optionally how many attributes it takes (`attribute_count`, `min`/`max`, for
example 2 for a range between two attributes). Filter rules that take no attribute (containers, `idlist`, `customsql`)
have none.

A package can name `conflicts` (Composer constraints, like `conflict` in `composer.json`).

Not part of it: whether an attribute can be sorted or searched. The code does not tell that reliably and the handbook
does not state it.

`bin/check-handbook` compares `frontend_editing`, `file_usage` and `works_with` with the overview "Datentypen als
Attribute anlegen" of the handbook (needs a connection, runs with the link check). Both have to tell the same; a
difference is an error. A new filter rule on that page has to be added to `HandbookTable::FILTERS` first.

```
bin/check-handbook                  # fetch the German page and compare
bin/check-handbook --file=page.html # compare with a saved copy
```

### Release lines

The catalog covers the lines of MetaModels from 2.4 on (2.4 with Contao 5.3, 2.5, 2.6), listed in `data/lines.json`. A
package has `lines`, one entry per line it exists for:

| Field | Meaning |
| --- | --- |
| `metamodels` | The line, for example `2.4`. |
| `requires` | What that line needs, from the `composer.json` of the branch of the line: `php`, `contao/core-bundle`, `metamodels/*` and `contao-community-alliance/*`. The framework packages are left out. |
| `availability` | How to get the package for this line. Left out when it is not stated: the `availability` of the package itself is the one of the newest line, do not take it for an older one. `bin/sync-lines --packagist` sets `public` for a line with a stable release on Packagist and `early-access` for an older line without one. |

A package without an entry for a line does not exist there. Types follow their package, so a type exists in the lines
of its package. The handbook pages are the same for all lines.

The values are read, not written by hand: `bin/sync-lines` reads the `composer.json` of the branch `feature/<line>.0`
(or the branch named in `data/lines.json` under `overrides`, for example for `metamodels/core`) in the clone of every
package. The clones have to lie next to this repo (`--packages-dir`), run `git fetch` there first. It needs the
clones, so it runs on a developer machine, not in the checks.

```
bin/sync-lines                  # write the lines
bin/sync-lines --packagist      # also ask Packagist: public release = public, older line without one = early-access
bin/sync-lines --check          # change nothing, exit 1 if a file would change
```

### Availability

| Value | Meaning |
| --- | --- |
| `public` | `composer require` works for everybody. |
| `early-access` | Only for supporters, `access` points to a page for humans (never credentials): `access.page` is a page of the handbook (a path, the address per language is built from `handbook.urls`), `access.url` an external address by language. The AI does not suggest `composer require`. |
| `planned` | Does not exist yet. |
| `unknown` | Not stated yet. The AI suggests `composer require` and says that many extensions are still part of the Early Adopter Program (EAP): if Composer cannot find the package or refuses access, the user has to ask about access. |

### Plans

`plans` names the supporter plans that include an `early-access` package: `basic-1` (the reduced plan for small
projects) and `eap` (the full Early Adopter Program). Both are described on the fundraising page that `access.url`
points to. A package with a fundraising of its own has no `plans`, its `access.page` is the page in the handbook.

## Layout

```
data/handbook.json                         where the handbook is, per language
data/packages/<vendor>/<package>.json      a package with its lines and types
data/lines.json                            the release lines and the branches they are read from
data/articles/<id>.json                    a page of the handbook
schema/metadata.schema.json                the format
bin/build                                  puts the files together and checks them
bin/sync-lines                             reads the lines of the packages from their clones
bin/check-handbook                         compares the data with the overview of attributes in the handbook
```

`bin/build` writes `build/metadata.json`, the file that is published, and `build/metadata.min.json`, the same content
without white space (about half the size) for readers that have to take the whole file into their context. It checks the references between the parts (the
name of a package is its file name, the id of an article is its file name, no type twice, articles are about types that
exist) and the result against the schema.

```
composer install
bin/build --check     # only check
bin/build             # write build/metadata.json and build/metadata.min.json
bin/build --urls      # the addresses of the handbook pages, for the link check
```

## Adding a package or a page

1. Add or change the file in `data/` and run `bin/build --check`.
2. Give types a short English `summary` and `keywords` per language (`{"en": [...], "de": [...]}`, `en` is
   required, up to 12 words per language; a word can mean different things in different languages, so the lists are
   not mixed; a term that users of both languages type goes into both lists), the page in the handbook as `docs` (path without the address, without an anchor: anchors differ per
   language).
3. Open a pull request. The checks run the tests, the build and a check of the links; the maintainers review the
   entries.

The handbook has no page of its own for MetaModels 2.6 yet. When it has, add it as an article of the kind `topic`.

Packages that are not freely available register the same way. Do not put access data or private repository addresses
in here.

## Publishing

On every push to `main` the file is built and published with GitHub Pages:
`https://metamodels.github.io/mcp-metadata/metadata.json`, next to it the same content without white space as
`https://metamodels.github.io/mcp-metadata/metadata.min.json` (for an AI that reads the file itself, without MCP).
`mcp-admin` takes the address from its option
`meta_models_mcp_admin.metadata.url`.

## Requirements

- PHP 8.4 or later
