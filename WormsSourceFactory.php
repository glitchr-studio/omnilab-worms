<?php

namespace Omnilab\Worms;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * WoRMS: marine taxa by AphiaID, name or an ITIS, NCBI or IUCN id, with
 * their classification, synonyms, vernacular names and cross-references -
 * no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     classification: true            # the lineage (a call)
 *     synonyms: true                  # the synonyms (a call)
 *     vernaculars: true               # the vernacular names (a call)
 *     external_ids: [tsn, ncbi, iucn] # the cross-references read, a call each
 *     language: eng                   # the vernacular names kept (ISO 639-3); null: all
 *     marine_only: false              # names and searches among marine taxa only
 *     throttle: 0                     # seconds between two calls: WoRMS publishes no rate
 *     base_uri: 'https://www.marinespecies.org/rest/'
 */
final class WormsSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'worms',
            'omnilab.required_options' => [],
            'classification' => true,
            'synonyms' => true,
            'vernaculars' => true,
            'external_ids' => ['tsn', 'ncbi', 'iucn'],
            'language' => 'eng',
            'marine_only' => false,
            'throttle' => 0.0,
            'base_uri' => WormsSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $language = $c->get('language');

        return new WormsSource(
            $this->http ?? HttpClient::create(),
            (bool) $c->get('classification'),
            (bool) $c->get('synonyms'),
            (bool) $c->get('vernaculars'),
            array_values((array) $c->get('external_ids')),
            \in_array($language, [null, '', 'all', false], true) ? null : (string) $language,
            (bool) $c->get('marine_only'),
            (string) $c->get('base_uri', WormsSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
