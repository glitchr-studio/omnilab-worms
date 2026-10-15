<?php

namespace Omnilab\Worms\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\Source\SourceInterface;
use Omnilab\Worms\WormsSourceFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from www.marinespecies.org/rest on 2026-10-15
 * (Tests/Fixtures/index.json names each one's address): the common dolphin
 * (AphiaID 137094) read whole - its record, classification, synonyms,
 * vernacular names, ITIS, NCBI and IUCN ids -, an unaccepted name
 * (383799), the dolphin by its TSN, NCBI taxon, IUCN id and name, unknown
 * ids and names (204), and the names beginning with "Delphinus" in two
 * pages of ten.
 */
final class WormsSourceTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

    private function source(array $options = [], ?string $failing = null): SourceInterface
    {
        $fixtures = __DIR__.'/Fixtures';
        $index = json_decode((string) file_get_contents($fixtures.'/index.json'), true);
        $http = new MockHttpClient(function (string $method, string $url, array $o) use ($fixtures, $index, $failing): MockResponse {
            $this->urls[] = rawurldecode($url);
            if (null !== $failing && str_contains($url, $failing)) {
                return new MockResponse('', ['http_code' => 500]);
            }
            $name = self::fixture($method, $url, $o['body'] ?? null);
            if (!isset($index[$name])) {
                self::fail('No recorded answer for '.rawurldecode($url));
            }

            return new MockResponse((string) file_get_contents($fixtures.'/'.$name), ['http_code' => $index[$name]['status']]);
        });

        return (new WormsSourceFactory($http))->create($options + ['mailto' => 'support@glitchr.io']);
    }

    private static function fixture(string $method, string $url, ?string $body): string
    {
        $parts = parse_url($url);
        $readable = strtolower(trim((string) preg_replace('~[^A-Za-z0-9]+~', '-', rawurldecode(($parts['path'] ?? '').'-'.($parts['query'] ?? ''))), '-'));

        return substr($readable, 0, 70).'-'.substr(md5($method.' '.$url.' '.$body), 0, 8).'.json';
    }

    private function fast(): SourceInterface
    {
        return $this->source(['classification' => false, 'synonyms' => false, 'vernaculars' => false, 'external_ids' => []]);
    }

    public function testCapabilities(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES], $this->source()->capabilities());
        self::assertSame('worms', $this->source()->getName());
    }

    public function testTheCommonDolphinReadWhole(): void
    {
        $dolphin = $this->source()->entity('137094');

        self::assertCount(7, $this->urls, 'its record, classification, synonyms, vernaculars, and one call per external id');
        self::assertSame(['worms:137094', 'Delphinus delphis', Domain::TAXONOMY, 'species'], [(string) $dolphin->id, $dolphin->label, $dolphin->domain, $dolphin->kind]);
        self::assertSame(['worms:137094', 'itis:180438', 'ncbi.taxon:9728', 'iucn:134817215'], $dolphin->identifiers()->keys(), 'the cross-references the Collector follows');
        self::assertSame(['accepted', 'Linnaeus, 1758', 'Delphinus delphis Linnaeus, 1758'], [$dolphin->value('taxonomic_status'), $dolphin->value('taxon_authority'), $dolphin->value('scientific_name')]);
        self::assertSame(['Animalia', 'Chordata', 'Mammalia', 'Cetartiodactyla', 'Delphinidae', 'Delphinus'], [$dolphin->value('kingdom'), $dolphin->value('phylum'), $dolphin->value('class'), $dolphin->value('order'), $dolphin->value('family'), $dolphin->value('genus')]);
        self::assertSame('Biota > Animalia > Chordata > Vertebrata > Gnathostomata > Tetrapoda > Mammalia > Theria > Cetartiodactyla > Cetancodonta > Cetacea > Odontoceti > Delphinidae > Delphinus', $dolphin->value('lineage'));
        self::assertSame([true, false], [$dolphin->value('marine'), $dolphin->value('terrestrial')]);
        self::assertSame(24, $dolphin->value('synonym_count'));
        self::assertCount(24, $dolphin->related('synonym'));
        self::assertContains('worms:383799', $dolphin->related('synonym')->keys(), 'Delphinus albimanus');
        self::assertSame(['worms:137015'], $dolphin->related('parent')->keys());
        self::assertSame('common dolphin', $dolphin->value('common_name'), 'the English names (language: eng)');
        self::assertContains('Delphinus albimanus', $dolphin->synonyms);
        self::assertNotContains('dauphin commun', $dolphin->synonyms);
        self::assertStringStartsWith('Fordyce, E.; Perrin, W.F. (2026). World Cetacea Database. Delphinus delphis', $dolphin->value('citation'));
        self::assertSame('https://www.marinespecies.org/aphia.php?p=taxdetails&id=137094', $dolphin->url);
        self::assertNull($dolphin->property('missing'));
    }

    public function testAnUnacceptedNameIsItselfItsAcceptedRelated(): void
    {
        $name = $this->source()->entity(Identifier::of(Scheme::WORMS, '383799'));

        self::assertSame(['Delphinus albimanus', 'unaccepted', 'synonym', 'Delphinus delphis Linnaeus, 1758'], [$name->label, $name->value('taxonomic_status'), $name->value('unaccept_reason'), $name->value('accepted_name')]);
        self::assertSame(['worms:137094'], $name->related('accepted')->keys());
        self::assertSame(['worms:383799'], $name->identifiers()->keys(), 'WoRMS answers 204 for its external ids');
    }

    public function testByAnotherBasesIdOrItsName(): void
    {
        foreach ([Identifier::of(Scheme::ITIS, '180438'), Identifier::of(Scheme::NCBI_TAXON, '9728'), 'iucn:134817215', 'Delphinus delphis', 'urn:lsid:marinespecies.org:taxname:137094'] as $id) {
            self::assertSame('worms:137094', (string) $this->fast()->entity($id)?->id, (string) $id);
        }
        self::assertContains('https://www.marinespecies.org/rest/AphiaRecordByExternalID/180438?type=tsn', $this->urls);
        self::assertContains('https://www.marinespecies.org/rest/AphiaRecordsByName/Delphinus delphis?like=false&marine_only=false', $this->urls);
    }

    public function testUnknownIsNullAndOtherSchemesAreRefused(): void
    {
        self::assertNull($this->fast()->entity('999999999'));
        self::assertNull($this->fast()->entity('Nothingus nowhereus'));
        self::assertNull($this->fast()->entity(Identifier::of(Scheme::ITIS, '999999999')));
        $this->urls = [];
        foreach (['Q140', Identifier::of(Scheme::GBIF, '4CGXP'), 'col:4CGXP'] as $id) {
            try {
                $this->fast()->entity($id);
                self::fail('not read: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->urls);
    }

    public function testWhatWormsFailsToGiveBesidesIsLeftOutAndSaid(): void
    {
        $dolphin = $this->source(failing: 'AphiaSynonymsByAphiaID')->entity('137094');

        self::assertSame('worms:137094', (string) $dolphin->id);
        self::assertSame([], $dolphin->related('synonym')->keys());
        self::assertSame('synonyms ([worms] the service answered 500)', $dolphin->value('missing'));
        self::assertSame('iucn:134817215', (string) $dolphin->identifiers->get(Scheme::IUCN), 'the rest read');
    }

    public function testSearchPageByOffset(): void
    {
        $source = $this->source();
        $first = $source->search(new Query('Delphinus', limit: 10));
        $second = $source->search(new Query('Delphinus', limit: 10, cursor: $first->next));

        self::assertSame('https://www.marinespecies.org/rest/AphiaRecordsByName/Delphinus?like=true&marine_only=false&offset=11', $this->urls[1]);
        self::assertSame(['Delphinus', 10, '11', 10, '21'], [$first->first()->label, \count($first), $first->next, \count($second), $second->next]);
        self::assertSame('Delphinus (Delphis)', $second->first()->label);
        self::assertSame([], $source->search(new Query('Delphinus', Domain::CHEMISTRY))->items);
    }
}
