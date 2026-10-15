# omnilab/worms

WoRMS, the World Register of Marine Species, for
[glitchr/omnilab](https://github.com/glitchr-studio/omnilab): a taxon by its AphiaID, its name, or
the ITIS TSN, NCBI taxon or IUCN id WoRMS knows it by - its accepted name and status, its
classification, its synonyms and vernacular names, WoRMS's habitat flags and the citation it asks
for; its ITIS, NCBI and IUCN ids as cross-references; its parent, its accepted name and its
synonyms as related things. A search of names. No key.

```php
$worms = (new WormsSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$dolphin = $worms->entity('137094');              // or 'worms:137094', its LSID, its page, 'Delphinus delphis',
                                                  // Identifier::of(Scheme::ITIS, '180438'), 'iucn:134817215'
$dolphin->identifiers()->keys();                  // worms:137094, itis:180438, ncbi.taxon:9728, iucn:134817215
$dolphin->value('lineage');                       // Biota > Animalia > Chordata > ... > Delphinidae > Delphinus
$dolphin->related('synonym')->keys();             // the 24 synonyms' AphiaIDs (worms:383799...)
$dolphin->value('citation');                      // Fordyce, E.; Perrin, W.F. (2026). World Cetacea Database...

$worms->search(new Query('Delphinus', limit: 10));  // the names beginning with it, 50 a page at most
```

```yaml
omnilab:
    sources:
        worms:
            factory: worms
            options:
                mailto: 'support@glitchr.io'     # in the User-Agent
                classification: true            # the lineage (a call)
                synonyms: true                  # the synonyms (a call)
                vernaculars: true               # the vernacular names (a call)
                external_ids: [tsn, ncbi, iucn] # the cross-references, a call each
                language: eng                   # the vernacular names kept (ISO 639-3); null: all
                marine_only: false              # names and searches among marine taxa only
```

WoRMS is slow (one to three seconds a call; on 2026-10-15 a synonyms call once answered 500 after
30 s): a taxon read whole takes seven calls, about eight seconds. Leave out what you do not need;
what WoRMS fails to give besides the record itself is left out and said (`missing`).

A taxon is named in the core's `worms` scheme, which `omnilab/obis` uses too: OBIS's records and
counts join WoRMS's names. The `Collector` follows WoRMS's ITIS TSN into `omnilab/itis`, its NCBI
taxon into `omnilab/ncbi`, its IUCN id into `omnilab/iucn`; and WoRMS reads those ids, so a
taxon reaches WoRMS from ITIS or NCBI as well.

## Terms of use

WoRMS's content is under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/); WoRMS asks
that it be cited - "WoRMS Editorial Board (2026). World Register of Marine Species. Available from
https://www.marinespecies.org at VLIZ. doi:10.14284/170" - and each taxon with its own citation
(`citation`). The REST service is free and needs no key; for large extracts WoRMS asks to be
contacted for a download rather than queried name by name
([marinespecies.org/aphia.php?p=webservice](https://www.marinespecies.org/aphia.php?p=webservice),
[usage](https://www.marinespecies.org/about.php#what_is_the_licence)).

See [docs/](docs/index.md) for what is read, how it maps, and what WoRMS does not give here.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the WoRMS Editorial Board and the Flanders Marine Institute (VLIZ). It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
