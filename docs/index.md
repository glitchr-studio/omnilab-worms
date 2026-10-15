# omnilab/worms

## Installation

```sh
composer require omnilab/worms
```

```php
use Omnilab\Worms\WormsSourceFactory;

$worms = (new WormsSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',   // optional, in the User-Agent
    'external_ids' => ['tsn', 'ncbi'],  // optional: the cross-references read
]);
```

With the Symfony bundle, the factory is registered as `worms` (see the core's docs).

## Which API

WoRMS's REST service, `https://www.marinespecies.org/rest/` (JSON; WoRMS answers 204 for
nothing). A taxon read whole:

1. `AphiaRecordByAphiaID/{id}` (or `AphiaRecordByExternalID/{id}?type=tsn|ncbi|iucn`, or
   `AphiaRecordsByName/{name}?like=false`): the record;
2. `AphiaClassificationByAphiaID/{id}`: the lineage (`classification`);
3. `AphiaSynonymsByAphiaID/{id}` (`synonyms`);
4. `AphiaVernacularsByAphiaID/{id}` (`vernaculars`);
5. `AphiaExternalIDByAphiaID/{id}?type=` for each of `external_ids` (WoRMS takes one type a call;
   its types are algaebase, bold, dyntaxa, fishbase, iucn, lsid, ncbi, tsn, gisd - only those the
   core has a scheme for are read).

## Calls

| Method | Reads | WoRMS |
|---|---|---|
| `entity()` | an AphiaID: `137094`, `worms:137094`, `aphia:137094`, `urn:lsid:marinespecies.org:taxname:137094`, its page | the record, then 2-5 |
| | an `Identifier` of `itis`, `ncbi.taxon` or `iucn` (`itis:180438`, `iucn:134817215`) | `AphiaRecordByExternalID`, then 2-5 |
| | an exact scientific name (`Delphinus delphis`); of several usages the accepted first | `AphiaRecordsByName/{name}?like=false`, then 2-5 |
| `search()` | `Query::$text`: the names that begin with it, accepted or not, WoRMS's order, page by offset (50 at most); a domain other than taxonomy answers nothing | `AphiaRecordsByName/{text}?like=true&offset=` |

An unaccepted name is itself (its status `unaccepted`, `unaccept_reason`, its accepted taxon
related). Unknown is null (204). An identifier of another scheme (a GBIF key, a Catalogue of Life
id, a Wikidata item) is refused before any call. A search's results hold the record alone (no
cross-references, synonyms or lineage); its total is not known.

## What an entity holds

`id` (`worms:137094`), `label` (its scientific name), `domain` taxonomy, `kind` (its rank, lower
case), `synonyms` (its vernacular names in `language`, then its scientific synonyms), `url` (its
WoRMS page), `identifiers` (`itis`, `ncbi.taxon`, `iucn`), and:

| Key | WoRMS | Value |
|---|---|---|
| `taxon_name`, `taxon_authority`, `scientific_name` | scientificname, authority, both | text |
| `taxon_rank`, `taxonomic_status`, `unaccept_reason` | rank, status, unacceptreason | text |
| `accepted_name` | valid_name and valid_authority, when another taxon | text |
| `kingdom`, `phylum`, `class`, `order`, `family`, `genus` | the record's | text |
| `lineage` | the classification, from Biota | text |
| `marine`, `brackish`, `freshwater`, `terrestrial`, `extinct` | isMarine... (WoRMS leaves some unset) | boolean |
| `common_name` | the first vernacular name in `language` | text |
| `synonym_count` | the synonyms' number | number |
| `citation` | the citation WoRMS asks for this taxon | text |
| `modified` | the day the record last changed | text |
| `missing` | what WoRMS failed to give this time (a 500, a time-out), when it did | text |

`related`: `parent` (parentNameUsageID), `accepted` (valid_AphiaID, for an unaccepted name),
`synonym` (the synonyms' AphiaIDs).

## What WoRMS does not give here

- Not read: the attributes (body size, functional group, Habitats Directive...), the
  distributions, the sources, the children, the BOLD, AlgaeBase, FishBase, Dyntaxa and GISD ids
  (no scheme in the core).
- No GBIF key, no Catalogue of Life id: the Catalogue of Life takes WoRMS as a source, and
  `omnilab/col` carries the AphiaID of a taxon it got from WoRMS.

## The Collector

- From WoRMS: `collect('worms', '137094', 1, ['itis', 'ncbi', 'iucn'])` follows its TSN, its NCBI
  taxon and its IUCN id.
- Into WoRMS: an entity carrying `itis:180438` or `ncbi.taxon:9728` (ITIS, NCBI, GBIF, the
  Catalogue of Life) reaches WoRMS by `AphiaRecordByExternalID`; OBIS's records and taxa are keyed
  `worms:` already.

## Verified, and not

Against the real service on 2026-10-15, without a key: the common dolphin read whole (seven calls,
8.5 s: its lineage from Biota, 24 synonyms, its English names, ITIS 180438, NCBI 9728, IUCN
134817215, the World Cetacea Database's citation); an unaccepted name (*Delphinus albimanus*,
383799); the dolphin by its TSN, its NCBI taxon, its IUCN id, its name and its LSID; unknown ids and
names (204); a search for "Delphinus" in two pages of ten. That evening a synonyms call answered
500 after 31 s, then 200 after 16 s, then after 2 s: hence `missing`. The fixtures in
`Tests/Fixtures/` are its answers (`index.json` names each one's address).

Not verified: a 429 (none met; WoRMS publishes no rate); `marine_only` live.
