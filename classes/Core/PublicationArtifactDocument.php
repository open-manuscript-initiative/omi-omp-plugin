<?php
namespace APP\plugins\generic\studioIntegration\classes\Core;

use DOMDocument;
use InvalidArgumentException;

/**
 * Validates publication artifacts and their OMI build provenance before OMP
 * stores them as unpublished publication-format proof files.
 *
 * The build manifest is verified independently of Studio. Artifact bytes,
 * filename, media type, manuscript identity and deterministic build id must all
 * agree before the transfer can proceed.
 */
final class PublicationArtifactDocument
{
    public const PROTOCOL = 'omi-publication-artifact/1';
    public const BUILD_MODEL = 'omi-publication-build';
    public const BUILD_VERSION = '0.1.0';

    private const JATS_DTD =
        'https://jats.nlm.nih.gov/articleauthoring/1.4/JATS-articleauthoring1-4-mathml3.dtd';

    private const FORMATS = [
        'html' => [
            'label' => 'HTML',
            'extension' => 'html',
            'mediaType' => 'text/html',
            'maxBytes' => 8388608,
        ],
        'jats' => [
            'label' => 'JATS XML',
            'extension' => 'xml',
            'mediaType' => 'application/xml',
            'maxBytes' => 8388608,
        ],
        'epub' => [
            'label' => 'EPUB',
            'extension' => 'epub',
            'mediaType' => 'application/epub+zip',
            'maxBytes' => 33554432,
        ],
        'pdf-print' => [
            'label' => 'PDF',
            'extension' => 'pdf',
            'mediaType' => 'application/pdf',
            'maxBytes' => 33554432,
        ],
        'pdf-interactive' => [
            'label' => 'PDF (Interactive)',
            'extension' => 'pdf',
            'mediaType' => 'application/pdf',
            'maxBytes' => 33554432,
        ],
    ];

    public static function formats(bool $htmlAvailable): array
    {
        $result = [];
        foreach (self::FORMATS as $id => $definition) {
            $result[] = [
                'id' => $id,
                'label' => $definition['label'],
                'mediaType' => $definition['mediaType'],
                'extension' => $definition['extension'],
                'maxBytes' => $definition['maxBytes'],
                'available' => ($id !== 'html' || $htmlAvailable) && ($id !== 'epub' || class_exists(\ZipArchive::class)),
                'requires' => $id === 'html' ? 'htmlMonographFilePlugin' : ($id === 'epub' ? 'php-zip' : null),
            ];
        }
        return $result;
    }

    public static function definition(string $format): array
    {
        if (!isset(self::FORMATS[$format])) {
            throw new InvalidArgumentException('Unsupported publication artifact format.');
        }
        return self::FORMATS[$format];
    }

    /**
     * @return array{bytes:string,sha256:string,buildId:string,fileName:string,mediaType:string,label:string,extension:string}
     */
    public static function validateTransfer(
        string $format,
        string $mediaType,
        string $fileName,
        string $artifactBase64,
        array $build,
        string $manuscriptId
    ): array {
        $definition = self::definition($format);
        $normalizedMediaType = self::normalizeMediaType($mediaType);

        if ($normalizedMediaType !== $definition['mediaType']) {
            throw new InvalidArgumentException(
                'The artifact media type does not match the selected publication format.'
            );
        }

        $fileName = trim($fileName);
        if (
            $fileName === '' ||
            basename($fileName) !== $fileName ||
            str_contains($fileName, "\0") ||
            !preg_match('/\.' . preg_quote($definition['extension'], '/') . '$/i', $fileName)
        ) {
            throw new InvalidArgumentException(
                'The publication artifact filename is invalid for the selected format.'
            );
        }

        $bytes = base64_decode($artifactBase64, true);
        if ($bytes === false) {
            throw new InvalidArgumentException('The publication artifact is not valid base64.');
        }

        $length = strlen($bytes);
        if ($length < 1 || $length > $definition['maxBytes']) {
            throw new InvalidArgumentException(
                sprintf(
                    'The %s artifact must be between 1 byte and %d bytes.',
                    $definition['label'],
                    $definition['maxBytes']
                )
            );
        }

        self::validateArtifactBytes($format, $bytes);
        $sha256 = hash('sha256', $bytes);
        $buildId = self::validateBuildManifest(
            $build,
            $format,
            $mediaType,
            $fileName,
            $manuscriptId,
            $length,
            $sha256
        );

        return [
            'bytes' => $bytes,
            'sha256' => $sha256,
            'buildId' => $buildId,
            'fileName' => $fileName,
            'mediaType' => $normalizedMediaType,
            'label' => $definition['label'],
            'extension' => $definition['extension'],
        ];
    }

    public static function path(string $manuscriptId, string $locale, string $format): string
    {
        self::definition($format);

        return 'omi-' . preg_replace('/[^a-z0-9]+/i', '-', $format)
            . '-' . substr(hash('sha256', $manuscriptId . ':' . $locale . ':' . $format), 0, 40);
    }

    private static function validateArtifactBytes(string $format, string $bytes): void
    {
        if ($format === 'html') {
            HtmlPublicationDocument::validate($bytes);
            return;
        }

        if ($format === 'jats') {
            self::validateJats($bytes);
            return;
        }

        if ($format === 'epub') {
            self::validateEpub($bytes);
            return;
        }

        if (!str_starts_with($bytes, '%PDF-')) {
            throw new InvalidArgumentException(
                'The PDF artifact does not have a valid PDF signature.'
            );
        }

        $tail = substr($bytes, -2048);
        if ($tail === false || !str_contains($tail, '%%EOF')) {
            throw new InvalidArgumentException('The PDF artifact is incomplete.');
        }
    }

    /**
     * Initially accept the static six-entry EPUB 3 profile emitted by Studio.
     * Never extract the archive, fetch XML dependencies or accept arbitrary
     * scripts, remote resources, extra entries or oversized decompression.
     * This is a transfer boundary, not an EPUBCheck conformance certificate.
     */
    private static function validateEpub(string $bytes): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new InvalidArgumentException('PHP ZIP support is required for EPUB transfer.');
        }
        $temporary = tmpfile();
        if (!$temporary) throw new InvalidArgumentException('Unable to inspect EPUB.');
        $zip = new \ZipArchive();
        $opened = false;
        try {
            if (fwrite($temporary, $bytes) !== strlen($bytes) || !fflush($temporary)) {
                throw new InvalidArgumentException('Unable to inspect EPUB.');
            }
            $opened = $zip->open(stream_get_meta_data($temporary)['uri'], \ZipArchive::RDONLY | \ZipArchive::CHECKCONS) === true;
            if (!$opened || $zip->numFiles !== 6) throw new InvalidArgumentException('Unsupported Studio EPUB package.');
            $required = ['mimetype', 'META-INF/container.xml', 'EPUB/package.opf', 'EPUB/nav.xhtml', 'EPUB/article.xhtml', 'EPUB/styles.css'];
            $contents = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat || !in_array($stat['name'], $required, true) || isset($contents[$stat['name']]) ||
                    $stat['size'] < 1 || $stat['size'] > 16777216 ||
                    !in_array($stat['comp_method'], [\ZipArchive::CM_STORE, \ZipArchive::CM_DEFLATE], true) ||
                    ($stat['encryption_method'] ?? 0) !== 0) {
                    throw new InvalidArgumentException('Unsupported or unsafe EPUB entry.');
                }
                $total += $stat['size'];
                if ($total > 33554432 || ($i === 0 && ($stat['name'] !== 'mimetype' || $stat['comp_method'] !== \ZipArchive::CM_STORE))) {
                    throw new InvalidArgumentException('Invalid EPUB package order or size.');
                }
                $value = $zip->getFromIndex($i, $stat['size']);
                if ($value === false || strlen($value) !== $stat['size']) throw new InvalidArgumentException('Unreadable EPUB entry.');
                $contents[$stat['name']] = $value;
            }
            if (($contents['mimetype'] ?? null) !== 'application/epub+zip') throw new InvalidArgumentException('Invalid EPUB mimetype.');
            // The first local header must also be the uncompressed mimetype.
            if (substr($bytes, 0, 4) !== "PK\x03\x04" || substr($bytes, 30, 8) !== 'mimetype' ||
                unpack('v', substr($bytes, 8, 2))[1] !== 0 ||
                unpack('v', substr($bytes, 26, 2))[1] !== 8 ||
                unpack('v', substr($bytes, 28, 2))[1] !== 0) {
                throw new InvalidArgumentException('Invalid EPUB first local header.');
            }
            $container = self::epubXml($contents['META-INF/container.xml'], 'container', 'urn:oasis:names:tc:opendocument:xmlns:container');
            $roots = $container->getElementsByTagNameNS('urn:oasis:names:tc:opendocument:xmlns:container', 'rootfile');
            if ($roots->length !== 1 || $roots->item(0)->getAttribute('full-path') !== 'EPUB/package.opf' ||
                $roots->item(0)->getAttribute('media-type') !== 'application/oebps-package+xml') {
                throw new InvalidArgumentException('Invalid EPUB container rootfile.');
            }
            $opf = self::epubXml($contents['EPUB/package.opf'], 'package', 'http://www.idpf.org/2007/opf');
            if ($opf->documentElement->getAttribute('version') !== '3.0') throw new InvalidArgumentException('EPUB 3 is required.');
            $items = $opf->getElementsByTagNameNS('http://www.idpf.org/2007/opf', 'item');
            $expected = ['article' => ['article.xhtml', 'application/xhtml+xml'], 'nav' => ['nav.xhtml', 'application/xhtml+xml'], 'css' => ['styles.css', 'text/css']];
            if ($items->length !== count($expected)) throw new InvalidArgumentException('Invalid EPUB manifest.');
            foreach ($items as $item) {
                $id = $item->getAttribute('id');
                if (!isset($expected[$id]) || $expected[$id] !== [$item->getAttribute('href'), $item->getAttribute('media-type')] ||
                    ($id === 'nav' ? $item->getAttribute('properties') !== 'nav' : $item->getAttribute('properties') !== '')) {
                    throw new InvalidArgumentException('Unsupported EPUB resource.');
                }
                unset($expected[$id]);
            }
            $spine = $opf->getElementsByTagNameNS('http://www.idpf.org/2007/opf', 'itemref');
            if ($spine->length !== 1 || $spine->item(0)->getAttribute('idref') !== 'article') throw new InvalidArgumentException('Invalid EPUB spine.');
            foreach (['EPUB/nav.xhtml', 'EPUB/article.xhtml'] as $name) {
                $html = self::epubXml(str_replace('<!DOCTYPE html>', '', $contents[$name]), 'html', 'http://www.w3.org/1999/xhtml');
                foreach ($html->getElementsByTagName('*') as $element) {
                    if ($element->namespaceURI !== 'http://www.w3.org/1999/xhtml' ||
                        !in_array($element->localName, ['html','head','meta','title','link','body','article','header','section','h1','h2','h3','h4','h5','h6','p','span','strong','br','nav','ol','li','a'], true)) {
                        throw new InvalidArgumentException('Unsupported or active EPUB content.');
                    }
                    foreach ($element->attributes as $attribute) {
                        $key = strtolower($attribute->nodeName);
                        if (str_starts_with($key, 'on') || in_array($key, ['src','srcset','style','action','formaction','http-equiv','xml:base'], true)) {
                            throw new InvalidArgumentException('Unsafe EPUB attribute.');
                        }
                        if ($key === 'href' && !($element->localName === 'link' && $attribute->value === 'styles.css') &&
                            !($element->localName === 'a' && preg_match('/^article\.xhtml#sec-[a-zA-Z0-9_.:-]+$/D', $attribute->value))) {
                            throw new InvalidArgumentException('External or unsupported EPUB link.');
                        }
                    }
                }
            }
            $css = $contents['EPUB/styles.css'];
            if (preg_match('/[@\\\\]|url\s*\(|expression\s*\(/i', $css)) throw new InvalidArgumentException('Unsafe EPUB stylesheet.');
        } finally {
            if ($opened) $zip->close();
            fclose($temporary);
        }
    }

    private static function epubXml(string $xml, string $root, string $namespace): DOMDocument
    {
        if (preg_match('/<!DOCTYPE\b|<!ENTITY\b|<\?(?!xml\s)/i', $xml)) throw new InvalidArgumentException('Unsafe EPUB XML declaration.');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            if (!$dom->loadXML($xml, LIBXML_NONET) || !$dom->documentElement ||
                $dom->documentElement->localName !== $root || $dom->documentElement->namespaceURI !== $namespace) {
                throw new InvalidArgumentException('Invalid EPUB XML.');
            }
            return $dom;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function validateJats(string $xml): void
    {
        if (preg_match('/<!ENTITY\b/i', $xml)) {
            throw new InvalidArgumentException('JATS entity declarations are not accepted.');
        }

        $expectedDoctype = '<!DOCTYPE article SYSTEM "' . self::JATS_DTD . '">';
        if (
            preg_match('/<!DOCTYPE\b/i', $xml) &&
            !str_contains($xml, $expectedDoctype)
        ) {
            throw new InvalidArgumentException(
                'The JATS artifact uses an unexpected DTD declaration.'
            );
        }

        if (preg_match('/<!DOCTYPE\s+article\s+SYSTEM\s+"[^"]+"\s*\[[\s\S]*?\]>/i', $xml)) {
            throw new InvalidArgumentException('JATS internal DTD subsets are not accepted.');
        }

        $parseXml = str_replace($expectedDoctype, '', $xml);
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            if (!$dom->loadXML($parseXml, LIBXML_NONET)) {
                throw new InvalidArgumentException('The JATS artifact is not well-formed XML.');
            }

            $root = $dom->documentElement;
            if (
                !$root ||
                $root->localName !== 'article' ||
                trim((string)$root->getAttribute('dtd-version')) !== '1.4'
            ) {
                throw new InvalidArgumentException('JATS 1.4 article XML is required.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function validateBuildManifest(
        array $build,
        string $format,
        string $mediaType,
        string $fileName,
        string $manuscriptId,
        int $byteLength,
        string $sha256
    ): string {
        if (
            ($build['model'] ?? null) !== self::BUILD_MODEL ||
            ($build['version'] ?? null) !== self::BUILD_VERSION
        ) {
            throw new InvalidArgumentException(
                'Unsupported OMI publication-build provenance model.'
            );
        }

        $id = trim((string)($build['id'] ?? ''));
        if (!preg_match('/^urn:omi:publication-build:sha256:[a-f0-9]{64}$/i', $id)) {
            throw new InvalidArgumentException('Invalid OMI publication-build identifier.');
        }

        $createdAt = trim((string)($build['createdAt'] ?? ''));
        if ($createdAt === '' || strtotime($createdAt) === false) {
            throw new InvalidArgumentException('The publication-build creation time is invalid.');
        }

        $manuscript = $build['manuscript'] ?? null;
        if (
            !is_array($manuscript) ||
            trim((string)($manuscript['id'] ?? '')) !== $manuscriptId ||
            trim((string)($manuscript['revisionId'] ?? '')) === '' ||
            !self::validDigest($manuscript['stateDigest'] ?? null)
        ) {
            throw new InvalidArgumentException(
                'The publication-build manuscript identity does not match this transfer.'
            );
        }

        $profile = $build['profile'] ?? null;
        if (
            !is_array($profile) ||
            trim((string)($profile['id'] ?? '')) === '' ||
            trim((string)($profile['version'] ?? '')) === '' ||
            !self::validDigest($profile['digest'] ?? null)
        ) {
            throw new InvalidArgumentException(
                'The publication-build profile provenance is invalid.'
            );
        }

        $output = $build['output'] ?? null;
        if (
            !is_array($output) ||
            ($output['format'] ?? null) !== $format ||
            self::normalizeMediaType((string)($output['mediaType'] ?? '')) !==
                self::normalizeMediaType($mediaType) ||
            (string)($output['fileName'] ?? '') !== $fileName ||
            (int)($output['byteLength'] ?? -1) !== $byteLength ||
            !self::validDigest($output['digest'] ?? null) ||
            !hash_equals(
                strtolower((string)$output['digest']['value']),
                strtolower($sha256)
            )
        ) {
            throw new InvalidArgumentException(
                'The publication-build output provenance does not match the transferred artifact.'
            );
        }

        $generator = $build['generator'] ?? null;
        if (
            !is_array($generator) ||
            ($generator['application'] ?? null) !== 'open-manuscript-studio' ||
            trim((string)($generator['applicationVersion'] ?? '')) === '' ||
            trim((string)($generator['renderer'] ?? '')) === '' ||
            trim((string)($generator['rendererVersion'] ?? '')) === ''
        ) {
            throw new InvalidArgumentException(
                'The publication-build generator provenance is invalid.'
            );
        }

        if (isset($build['rendererInput'])) {
            $rendererInput = $build['rendererInput'];
            if (
                !is_array($rendererInput) ||
                trim((string)($rendererInput['mediaType'] ?? '')) === '' ||
                (int)($rendererInput['byteLength'] ?? -1) < 0 ||
                !self::validDigest($rendererInput['digest'] ?? null)
            ) {
                throw new InvalidArgumentException(
                    'The publication-build renderer-input provenance is invalid.'
                );
            }
        }

        $identity = [
            'model' => $build['model'],
            'version' => $build['version'],
            'manuscript' => $manuscript,
            'profile' => $profile,
            'output' => $output,
        ];

        if (isset($build['rendererInput'])) {
            $identity['rendererInput'] = $build['rendererInput'];
        }

        $identity['generator'] = $generator;

        $expectedId =
            'urn:omi:publication-build:sha256:' .
            hash('sha256', self::canonicalJson($identity));

        if (!hash_equals(strtolower($expectedId), strtolower($id))) {
            throw new InvalidArgumentException(
                'The publication-build identifier does not match its provenance payload.'
            );
        }

        return $id;
    }

    private static function validDigest(mixed $value): bool
    {
        return is_array($value)
            && ($value['algorithm'] ?? null) === 'sha256'
            && preg_match('/^[a-f0-9]{64}$/i', (string)($value['value'] ?? '')) === 1;
    }

    private static function normalizeMediaType(string $mediaType): string
    {
        return strtolower(trim(explode(';', $mediaType, 2)[0]));
    }

    private static function canonicalJson(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (
            is_bool($value) ||
            is_int($value) ||
            is_float($value) ||
            is_string($value)
        ) {
            $encoded = json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if ($encoded === false) {
                throw new InvalidArgumentException(
                    'Unable to canonicalize publication-build provenance.'
                );
            }
            return $encoded;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException(
                'Unsupported value in publication-build provenance.'
            );
        }

        if (array_is_list($value)) {
            return '[' . implode(',', array_map(self::canonicalJson(...), $value)) . ']';
        }

        ksort($value, SORT_STRING);
        $parts = [];
        foreach ($value as $key => $item) {
            $encodedKey = json_encode(
                (string)$key,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if ($encodedKey === false) {
                throw new InvalidArgumentException(
                    'Unable to canonicalize publication-build provenance.'
                );
            }
            $parts[] = $encodedKey . ':' . self::canonicalJson($item);
        }

        return '{' . implode(',', $parts) . '}';
    }
}
