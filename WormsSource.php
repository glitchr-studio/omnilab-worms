<?php

namespace Omnilab\Worms;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * WoRMS, the World Register of Marine Species (VLIZ), through its REST
 * service (www.marinespecies.org/rest): a taxon by its AphiaID, its name,
 * or the ITIS TSN, NCBI taxon or IUCN id WoRMS knows it by - its accepted
 * name and status, its classification, its synonyms, its vernacular names,
 * WoRMS's habitat flags and the citation it asks for; its ITIS, NCBI and
 * IUCN ids as cross-references; its parent, its accepted name and its
 * synonyms as related things. A search of names, 50 a page.
 *
 * No key, no published rate; each call takes one to three seconds, and a
 * taxon read whole takes up to seven (the record, its classification,
 * synonyms, vernaculars, and one call per external id): the options leave
 * out what is not needed.
 */
final class WormsSource extends HttpSource
{
    public const BASE_URI = 'https://www.marinespecies.org/rest/';

    /** WoRMS's page: 50 records. */
    public const MAX_PER_PAGE = 50;

    /** The external ids read by default, each a call: WoRMS's type => the core's scheme. */
    public const EXTERNAL_IDS = ['tsn' => Scheme::ITIS, 'ncbi' => Scheme::NCBI_TAXON, 'iucn' => Scheme::IUCN];

    private const MAIN_RANKS = ['kingdom', 'phylum', 'class', 'order', 'family', 'genus'];

    /**
     * @param list<string> $externalIds  the external ids read (tsn, ncbi, iucn), one call each
     * @param string|null  $language     the vernacular names kept, by ISO 639-3 code (eng, fra...); null: all
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly bool $classification = true,
        private readonly bool $synonyms = true,
        private readonly bool $vernaculars = true,
        private readonly array $externalIds = ['tsn', 'ncbi', 'iucn'],
        private readonly ?string $language = 'eng',
        private readonly bool $marineOnly = false,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        foreach ($externalIds as $type) {
            if (!isset(self::EXTERNAL_IDS[$type])) {
                throw new \InvalidArgumentException(\sprintf('"%s" is no external id this source reads (%s).', $type, implode(', ', array_keys(self::EXTERNAL_IDS))));
            }
        }
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'worms';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES];
    }

    /**
     * A taxon by its AphiaID (137094, worms:137094, its LSID, its page), by
     * an ITIS TSN, an NCBI taxon or an IUCN id WoRMS holds (an Identifier,
     * itis:180438), or by its exact scientific name (the accepted usage
     * first when the name has several): the record as WoRMS keeps it - an
     * unaccepted name is itself, its accepted name related -, then what the
     * options ask for.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $external = array_flip(array_map(static fn (Scheme $s) => $s->value, self::EXTERNAL_IDS));
        $schemes = [Scheme::WORMS, ...array_values(self::EXTERNAL_IDS)];
        if ($id instanceof Identifier) {
            $identifier = \in_array($id->scheme, $schemes, true) ? $id : throw NotSupportedException::identifier('worms', (string) $id);
        } else {
            $text = trim($id);
            $identifier = Identifier::tryOf(Scheme::WORMS, $text) ?? Identifier::parse($text);
            if (null !== $identifier && !\in_array($identifier->scheme, $schemes, true)) {
                throw NotSupportedException::identifier('worms', $id);
            }
            if (null === $identifier) {
                return '' === $text ? null : $this->byName($text);
            }
        }
        $record = Scheme::WORMS === $identifier->scheme
            ? $this->getJson('AphiaRecordByAphiaID/'.$identifier->value)
            : $this->getJson('AphiaRecordByExternalID/'.rawurlencode($identifier->value), ['type' => $external[$identifier->scheme->value]]);

        return \is_array($record) && isset($record['AphiaID']) ? $this->whole($record) : null;
    }

    /**
     * WoRMS's names that begin with the text (like=true), accepted or not,
     * in WoRMS's order, page by offset (50 a page at most); the total is not
     * known. A domain other than taxonomy answers nothing.
     */
    public function search(Query $query): Page
    {
        $text = trim($query->text);
        if ((null !== $query->domain && Domain::TAXONOMY !== $query->domain) || '' === $text) {
            return new Page();
        }
        $size = max(1, min(self::MAX_PER_PAGE, $query->limit));
        $offset = null === $query->cursor ? 1 : max(1, (int) $query->cursor);
        $records = $this->getJson('AphiaRecordsByName/'.rawurlencode($text), ['like' => 'true', 'marine_only' => $this->marineOnly ? 'true' : 'false', 'offset' => $offset]) ?? [];
        $records = array_values(array_filter($records, static fn (mixed $r) => \is_array($r) && isset($r['AphiaID'])));
        $more = \count($records) > $size || self::MAX_PER_PAGE === \count($records);
        $entities = array_map(fn (array $r) => $this->toEntity($r), \array_slice($records, 0, $size));

        return new Page($entities, $more ? (string) ($offset + $size) : null);
    }

    private function byName(string $name): ?Entity
    {
        $records = $this->getJson('AphiaRecordsByName/'.rawurlencode($name), ['like' => 'false', 'marine_only' => $this->marineOnly ? 'true' : 'false']) ?? [];
        $records = array_values(array_filter($records, static fn (mixed $r) => \is_array($r) && isset($r['AphiaID'])));
        usort($records, static fn (array $a, array $b) => ('accepted' === ($b['status'] ?? null)) <=> ('accepted' === ($a['status'] ?? null)));

        return isset($records[0]) ? $this->whole($records[0]) : null;
    }

    /** @param array<string, mixed> $record */
    private function whole(array $record): Entity
    {
        $id = (string) $record['AphiaID'];
        // The record is the answer; what WoRMS fails to give besides (a 500 after 30 s on 2026-10-15, for the synonyms) is left out and said.
        $missing = [];
        $extra = function (string $what, string $path, array $query = []) use (&$missing): ?array {
            try {
                return $this->getJson($path, $query);
            } catch (UnavailableException $e) {
                $missing[] = $what.' ('.$e->getMessage().')';

                return null;
            }
        };
        $classification = $this->classification ? $extra('classification', 'AphiaClassificationByAphiaID/'.$id) : null;
        $synonyms = $this->synonyms ? $extra('synonyms', 'AphiaSynonymsByAphiaID/'.$id) ?? [] : [];
        $vernaculars = $this->vernaculars ? $extra('vernacular names', 'AphiaVernacularsByAphiaID/'.$id) ?? [] : [];
        $identifiers = [];
        foreach ($this->externalIds as $type) {
            foreach ($extra($type.' id', 'AphiaExternalIDByAphiaID/'.$id, ['type' => $type]) ?? [] as $value) {
                $identifiers[] = Identifier::tryOf(self::EXTERNAL_IDS[$type], \is_scalar($value) ? (string) $value : null);
            }
        }
        $entity = $this->toEntity($record, $classification, $synonyms, $vernaculars, new Identifiers($identifiers));

        return $missing ? $entity->with(['properties' => [...$entity->properties, new Property('missing', 'Not given by WoRMS this time', implode('; ', $missing), 'worms')]]) : $entity;
    }

    /**
     * @param array<string, mixed>      $r
     * @param array<string, mixed>|null $classification the nested chain from the root
     * @param array<mixed>              $synonyms
     * @param array<mixed>              $vernaculars
     */
    private function toEntity(array $r, ?array $classification = null, array $synonyms = [], array $vernaculars = [], Identifiers $identifiers = new Identifiers()): Entity
    {
        $id = (string) $r['AphiaID'];
        $name = self::text($r['scientificname'] ?? null) ?? $id;
        $rank = null !== ($value = self::text($r['rank'] ?? null)) ? strtolower($value) : null;
        $authority = self::text($r['authority'] ?? null);
        $properties = [];
        $add = static function (string $key, string $label, string|int|bool|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'worms');
            }
        };
        $add('taxon_name', 'Taxon name', $name);
        $add('taxon_authority', 'Authority', $authority);
        $add('scientific_name', 'Scientific name', null === $authority ? $name : $name.' '.$authority);
        $add('taxon_rank', 'Taxon rank', $rank);
        $add('taxonomic_status', 'Taxonomic status', self::text($r['status'] ?? null));
        $add('unaccept_reason', 'Why unaccepted', self::text($r['unacceptreason'] ?? null));
        $valid = self::text($r['valid_name'] ?? null);
        $add('accepted_name', 'Accepted name', null !== $valid && (string) ($r['valid_AphiaID'] ?? $id) !== $id ? trim($valid.' '.($r['valid_authority'] ?? '')) : null);
        foreach (self::MAIN_RANKS as $level) {
            $add($level, ucfirst($level), self::text($r[$level] ?? null));
        }
        $lineage = [];
        for ($node = $classification; \is_array($node) && isset($node['AphiaID']); $node = $node['child'] ?? null) {
            if ((string) $node['AphiaID'] !== $id && null !== ($n = self::text($node['scientificname'] ?? null))) {
                $lineage[] = $n;
            }
        }
        $add('lineage', 'Lineage', $lineage ? implode(' > ', $lineage) : null);
        foreach (['isMarine' => 'marine', 'isBrackish' => 'brackish', 'isFreshwater' => 'freshwater', 'isTerrestrial' => 'terrestrial', 'isExtinct' => 'extinct'] as $field => $key) {
            $add($key, ucfirst($key), isset($r[$field]) ? (bool) $r[$field] : null);
        }
        $add('citation', 'Citation (as WoRMS asks)', self::text($r['citation'] ?? null));
        $add('modified', 'Modified (in WoRMS)', null !== ($m = self::text($r['modified'] ?? null)) ? substr($m, 0, 10) : null);

        $names = [];
        foreach ($vernaculars as $v) {
            if (\is_array($v) && null !== ($n = self::text($v['vernacular'] ?? null)) && (null === $this->language || $this->language === ($v['language_code'] ?? null))) {
                $names[] = $n;
            }
        }
        $add('common_name', 'Common name', $names[0] ?? null);
        $synonymIds = [];
        foreach ($synonyms as $s) {
            if (\is_array($s) && null !== ($n = self::text($s['scientificname'] ?? null))) {
                $names[] = $n;
                $synonymIds[] = Identifier::tryOf(Scheme::WORMS, $s['AphiaID'] ?? null);
            }
        }
        $add('synonym_count', 'Synonyms (in WoRMS)', $synonyms ? \count($synonymIds) : null);
        $accepted = isset($r['valid_AphiaID']) && (string) $r['valid_AphiaID'] !== $id ? Identifier::tryOf(Scheme::WORMS, $r['valid_AphiaID']) : null;
        $parent = isset($r['parentNameUsageID']) && (string) $r['parentNameUsageID'] !== $id ? Identifier::tryOf(Scheme::WORMS, $r['parentNameUsageID']) : null;

        return new Entity(
            id: Identifier::of(Scheme::WORMS, $id),
            label: $name,
            domain: Domain::TAXONOMY,
            kind: $rank,
            synonyms: array_values(array_unique(array_diff($names, [$name]))),
            properties: $properties,
            identifiers: $identifiers,
            url: 'https://www.marinespecies.org/aphia.php?p=taxdetails&id='.$id,
            source: 'worms',
            related: array_filter([
                'parent' => Identifiers::of($parent),
                'accepted' => Identifiers::of($accepted),
                'synonym' => new Identifiers($synonymIds),
            ], static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }
}
