<?php
namespace APP\plugins\generic\studioIntegration\classes\Adapters;

use APP\core\Application;
use APP\facades\Repo;
use PKP\controlledVocab\ControlledVocab;
use PKP\db\DAORegistry;

final class Omp35Adapter
{
    public const PROFILE = 'omi-integration/1/omp';

    public function getSubmission(int $submissionId, int $contextId): ?object
    {
        $submission = Repo::submission()->get($submissionId, $contextId);
        if (!$submission || (int)$submission->getData('contextId') !== $contextId) {
            return null;
        }
        return $submission;
    }

    public function mapContext($context, $request): array
    {
        return [
            'externalId' => (string)$context->getId(),
            'type' => 'press',
            'path' => $context->getPath(),
            'name' => (array)$context->getData('name'),
            'url' => $request->url($context->getPath()),
        ];
    }

    public function mapSubmission(object $submission): array
    {
        $publication = $this->getHydratedCurrentPublication($submission);
        $primaryLocale = (string)$submission->getData('locale');

        return [
            'externalId' => (string)$submission->getId(),
            'type' => 'monograph',
            'status' => $this->mapStage((int)$submission->getData('stageId')),
            'stageId' => (int)$submission->getData('stageId'),
            'primaryLocale' => $primaryLocale,
            'title' => $publication ? (array)$publication->getData('title') : [],
            'subtitle' => $publication ? (array)$publication->getData('subtitle') : [],
            'abstract' => $publication ? (array)$publication->getData('abstract') : [],
            'prefix' => $publication ? (array)$publication->getData('prefix') : [],
            'keywords' => $publication
                ? $this->getControlledVocab($publication, ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD, $primaryLocale)
                : [],
            'metadata' => $publication ? [
                'subjects' => $this->getControlledVocab($publication, ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_SUBJECT, $primaryLocale),
                'disciplines' => $this->getControlledVocab($publication, ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_DISCIPLINE, $primaryLocale),
                'supportingAgencies' => $this->getControlledVocab($publication, ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_AGENCY, $primaryLocale),
                'coverage' => $this->normalizeLocaleObject($publication->getData('coverage')),
                'rights' => $this->normalizeLocaleObject($publication->getData('rights')),
                'source' => $this->normalizeLocaleObject($publication->getData('source')),
                'type' => $this->normalizeLocaleObject($publication->getData('type')),
                'publisherId' => $this->nullableString($publication->getData('pub-id::publisher-id')),
                'licenseUrl' => $this->nullableString($publication->getData('licenseUrl')),
                'copyrightHolder' => $this->normalizeLocaleObject($publication->getData('copyrightHolder')),
                'copyrightYear' => $publication->getData('copyrightYear') !== null ? (int)$publication->getData('copyrightYear') : null,
                'datePublished' => $this->formatDate($publication->getData('datePublished')),
            ] : [],
            'publicationExternalId' => $publication ? (string)$publication->getId() : null,
            'updatedAt' => $this->formatDate($publication?->getData('lastModified') ?? $submission->getData('lastModified')),
            'extensions' => [
                'org.pkp.omp' => [
                    'stageId' => (int)$submission->getData('stageId'),
                    'status' => $submission->getData('status'),
                    'publicationId' => $publication ? (int)$publication->getId() : null,
                    'seriesId' => $publication?->getData('seriesId'),
                    'seriesPosition' => $publication?->getData('seriesPosition'),
                ],
            ],
        ];
    }

    public function mapContributors(object $submission): array
    {
        $publication = $this->getHydratedCurrentPublication($submission);
        if (!$publication) return [];
        $authors = $publication->getData('authors');
        if (!$authors) return [];

        $result = [];
        foreach ($authors as $author) {
            $orcid = trim((string)$author->getData('orcid'));
            $result[] = [
                'externalId' => (string)$author->getId(),
                'name' => [
                    'given' => (string)$author->getLocalizedGivenName(),
                    'family' => (string)$author->getLocalizedFamilyName(),
                ],
                'email' => (string)($author->getData('email') ?? ''),
                'affiliation' => $author->getLocalizedAffiliationNamesAsString(),
                'country' => $author->getData('country'),
                'roles' => ['author'],
                'sequence' => $author->getSequence(),
                'primaryContact' => (bool)$author->getPrimaryContact(),
                'isEditor' => method_exists($author, 'getIsEditor') ? (bool)$author->getIsEditor() : false,
                'identifiers' => $orcid !== '' ? [['scheme' => 'orcid', 'value' => $orcid]] : [],
                'scope' => ['type' => 'submission', 'externalId' => (string)$submission->getId()],
            ];
        }
        return $result;
    }

    public function mapFiles(object $submission): array
    {
        $files = Repo::submissionFile()->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->getMany();

        /** @var \PKP\submission\GenreDAO $genreDao */
        $genreDao = DAORegistry::getDAO('GenreDAO');
        $contextId = (int)$submission->getData('contextId');
        $result = [];

        foreach ($files as $file) {
            $genreId = (int)($file->getData('genreId') ?? 0);
            $genre = $genreId > 0 ? $genreDao->getById($genreId, $contextId) : null;
            $result[] = [
                'externalId' => (string)$file->getId(),
                'name' => (string)($file->getData('originalFileName') ?? $file->getData('name', $submission->getData('locale')) ?? ''),
                'mediaType' => (string)($file->getData('mimetype') ?? ''),
                'size' => $file->getData('fileSize'),
                'stage' => (int)$file->getData('fileStage'),
                'genreExternalId' => $genreId > 0 ? (string)$genreId : null,
                'genreKey' => $genre ? $this->nullableString($genre->getKey()) : null,
                'genreName' => $genre ? $this->nullableString($genre->getLocalizedName()) : null,
                'genreCategory' => $genre ? (int)$genre->getCategory() : null,
                'revision' => $file->getData('revision'),
                'assocType' => $file->getData('assocType'),
                'assocId' => $file->getData('assocId'),
                'createdAt' => $this->formatDate($file->getData('createdAt')),
                'updatedAt' => $this->formatDate($file->getData('updatedAt')),
            ];
        }
        return $result;
    }

    private function getHydratedCurrentPublication(object $submission): ?object
    {
        $current = $submission->getCurrentPublication();
        if (!$current) return null;
        $publicationId = (int)$current->getId();
        $submissionId = (int)$submission->getId();
        if ($publicationId <= 0 || $submissionId <= 0) return $current;
        return Repo::publication()->get($publicationId, $submissionId) ?? $current;
    }

    private function getControlledVocab(object $publication, string $symbolic, string $primaryLocale): array
    {
        $publicationId = (int)$publication->getId();
        if ($publicationId <= 0) return [];
        $values = Repo::controlledVocab()->getBySymbolic(
            $symbolic,
            Application::ASSOC_TYPE_PUBLICATION,
            $publicationId,
            [],
            false
        );
        return $this->normalizeLocalizedKeywords($values, $primaryLocale);
    }

    private function normalizeLocaleObject(mixed $value): array
    {
        if ($value === null) return [];
        if (is_object($value)) {
            $value = $value instanceof \Traversable ? iterator_to_array($value) : (array)$value;
        }
        if (!is_array($value)) return [];
        $result = [];
        foreach ($value as $locale => $text) {
            if (!is_scalar($text)) continue;
            $normalized = trim((string)$text);
            if ($normalized !== '') $result[(string)$locale] = $normalized;
        }
        return $result;
    }

    private function normalizeLocalizedKeywords(mixed $value, string $primaryLocale): array
    {
        if ($value === null) return [];
        if (is_object($value)) {
            $value = $value instanceof \Traversable ? iterator_to_array($value) : (array)$value;
        }
        if (is_string($value)) {
            $items = $this->normalizeKeywordList($value);
            return $items === [] ? [] : [$primaryLocale => $items];
        }
        if (!is_array($value)) return [];
        if (array_is_list($value)) {
            $items = $this->normalizeKeywordList($value);
            return $items === [] ? [] : [$primaryLocale => $items];
        }
        $result = [];
        foreach ($value as $locale => $keywords) {
            if ($keywords instanceof \Traversable) $keywords = iterator_to_array($keywords);
            $items = $this->normalizeKeywordList($keywords);
            if ($items !== []) $result[(string)$locale] = $items;
        }
        return $result;
    }

    private function normalizeKeywordList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\s*[;,]\s*/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($value)) return [];
        $result = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) continue;
            $text = trim((string)$item);
            if ($text !== '') $result[] = $text;
        }
        return array_values(array_unique($result));
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    private function mapStage(int $stageId): string
    {
        return match ($stageId) {
            1 => 'submission',
            2 => 'internal-review',
            3 => 'external-review',
            4 => 'copyediting',
            5 => 'production',
            default => 'unknown',
        };
    }

    private function formatDate(mixed $value): ?string
    {
        if (!$value) return null;
        try {
            return (new \DateTimeImmutable((string)$value))->format(DATE_ATOM);
        } catch (\Throwable) {
            return (string)$value;
        }
    }
}
