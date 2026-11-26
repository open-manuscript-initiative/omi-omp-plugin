<?php
namespace PKP\submissionFile {
    class SubmissionFile {
        const SUBMISSION_FILE_PRODUCTION_READY = 10;
        const SUBMISSION_FILE_COPYEDIT = 11;
        const SUBMISSION_FILE_FINAL = 12;
        const SUBMISSION_FILE_SUBMISSION = 13;
    }
}
namespace PKP\db {
    class DAORegistry {
        public static object $dao;
        public static function getDAO(string $name): object {
            if ($name !== 'ChapterDAO') throw new \RuntimeException('Unexpected DAO');
            return self::$dao;
        }
    }
}
namespace {
    require_once __DIR__ . '/../classes/Adapters/Omp35Adapter.php';
    use APP\plugins\generic\studioIntegration\classes\Adapters\Omp35Adapter;
    class FixtureChapter {
        public function __construct(private int $id, private float $sequence) {}
        public function getId(): int { return $this->id; }
        public function getSourceChapterId(): ?int { return $this->id + 100; }
        public function getSequence(): float { return $this->sequence; }
        public function getTitle(): array { return ['hu' => 'Fejezet', 'en' => 'Chapter']; }
        public function getSubtitle(): ?array { return null; }
        public function getAbstract(): array { return ['hu' => 'Kivonat']; }
        public function getDoi(): ?string { return '10.1234/chapter'; }
        public function getPages(): ?string { return '12-20'; }
        public function getAuthors(): array { return [new class {
            public function getId(): int { return 99; }
        }]; }
    }
    \PKP\db\DAORegistry::$dao = new class {
        public function getByPublicationId(int $id): object {
            if ($id !== 42) throw new \RuntimeException('Wrong publication');
            return new class {
                public function toArray(): array {
                    return [new FixtureChapter(8, 2), new FixtureChapter(9, 1), new FixtureChapter(7, 1)];
                }
            };
        }
    };
    $chapters = (new Omp35Adapter())->mapVolumeChapters(42);
    if (array_column($chapters, 'externalId') !== ['7', '9', '8']
        || $chapters[0]['title'] !== ['hu' => 'Fejezet', 'en' => 'Chapter']
        || $chapters[0]['contributorExternalIds'] !== ['99']
        || $chapters[0]['publicationExternalId'] !== '42'
        || $chapters[0]['pages'] !== '12-20'
        || $chapters[0]['sourceChapterId'] !== '107') {
        throw new \RuntimeException('Chapter order, localizations or identity lost.');
    }
    $file = static fn ($id, $stage, $genre, $chapter, $name = 'source.docx') => [
        'externalId' => (string)$id, 'stage' => $stage, 'genreKey' => $genre,
        'chapterExternalId' => $chapter, 'name' => $name,
    ];
    $files = [
        $file(1, 13, 'MANUSCRIPT', null),
        $file(2, 10, 'CHAPTER', '7'),
        $file(3, 99, 'CHAPTER', '7'),
        $file(4, 10, 'CHAPTER', '8'),
        $file(5, 10, 'CHAPTER', null),
        $file(6, 10, 'MANUSCRIPT', '7'),
        $file(7, 10, 'CHAPTER', '7', 'source.pdf'),
        $file(8, 10, 'APPENDIX', null),
        $file(9, 11, 'CHAPTER', '7'),
    ];
    $result = Omp35Adapter::volumeSourceDescriptors($files, ['7']);
    if (array_column($result, 'externalId') !== ['2', '9', '1']) {
        throw new \RuntimeException('Volume source eligibility or priority is incorrect.');
    }
    $controller = file_get_contents(__DIR__ . '/../StudioIntegrationApiController.php');
    $start = strpos($controller, 'public function volumeImportManifest');
    $end = strpos($controller, 'public function submission(', $start);
    $handler = substr($controller, $start, $end - $start);
    foreach (['getAccessibleWorkflowStages', 'Role::ROLE_ID_MANAGER', 'Role::ROLE_ID_SITE_ADMIN',
        'Submission::STATUS_QUEUED', 'currentPublicationId', 'private, no-store',
        "'binaryTransfer' => ['available' => false]"] as $guard) {
        if (!str_contains($handler, $guard)) {
            throw new \RuntimeException('Missing volume manifest boundary: ' . $guard);
        }
    }
    echo "OMP volume manifest contract passed.\n";
}
