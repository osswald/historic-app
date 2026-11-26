<?php

declare(strict_types=1);

namespace App\Application\Nextcloud;

use App\Application\InvalidRequest;
use App\Application\UpstreamUnavailable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class NextcloudClient
{
    public const ROOT = 'Fahrten_Anlässe';

    private const PROPFIND = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
          <d:prop>
            <d:displayname/>
            <d:resourcetype/>
            <oc:fileid/>
            <oc:id/>
          </d:prop>
        </d:propfind>
        XML;

    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    /** @return list<NextcloudEntry> */
    public function eventFolders(string $base, string $user, string $password): array
    {
        $entries = $this->propfind($base, $user, $password, self::ROOT, '1');
        $choices = [];
        foreach ($entries as $entry) {
            if (!$entry->isCollection() || $entry->fileId() === '') {
                continue;
            }
            $relative = $this->directChild(self::ROOT, $entry->path());
            if ($relative === null || $relative === '_ARCHIV' || str_starts_with($relative, '_TEST_')) {
                continue;
            }
            $choices[] = $entry;
        }
        usort($choices, static fn (NextcloudEntry $a, NextcloudEntry $b): int => strnatcasecmp($a->name(), $b->name()));

        return $choices;
    }

    public function createEventFolder(string $base, string $user, string $password, string $name): NextcloudEntry
    {
        $this->assertFolderName($name);
        $path = self::ROOT.'/'.$name;
        $status = $this->status('MKCOL', $this->filesUrl($base, $user, $path), $user, $password, [], null);
        if ($status === 405) {
            throw new InvalidRequest(['name' => 'Dieser Ordner besteht schon. Bitte den bestehenden Ordner wählen.']);
        }
        if ($status === 404 || $status === 409) {
            throw new NextcloudNotFound('Der Ordner Fahrten_Anlässe ist in Nextcloud nicht vorhanden.');
        }
        if ($status !== 201) {
            $this->fail($status);
        }
        $entry = $this->one($this->propfind($base, $user, $password, $path, '0'), $path);
        if (!$entry instanceof NextcloudEntry || $entry->fileId() === '') {
            throw new UpstreamUnavailable('Nextcloud hat keine Datei-Kennung geliefert.');
        }

        return $entry;
    }

    public function findByFileId(string $base, string $user, string $password, string $fileId): ?NextcloudEntry
    {
        if (preg_match('/^[0-9]{1,20}$/', $fileId) !== 1) {
            return null;
        }
        $this->guard($base);
        $body = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <d:searchrequest xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
              <d:basicsearch>
                <d:select>
                  <d:prop>
                    <d:displayname/>
                    <d:resourcetype/>
                    <oc:fileid/>
                    <oc:id/>
                  </d:prop>
                </d:select>
                <d:from>
                  <d:scope>
                    <d:href>/files/{$this->xml($this->encodedUser($user))}</d:href>
                    <d:depth>infinity</d:depth>
                  </d:scope>
                </d:from>
                <d:where>
                  <d:eq>
                    <d:prop><oc:fileid/></d:prop>
                    <d:literal>{$fileId}</d:literal>
                  </d:eq>
                </d:where>
                <d:orderby/>
              </d:basicsearch>
            </d:searchrequest>
            XML;
        [$status, $content] = $this->exchange('SEARCH', $this->davUrl($base), $user, $password, [
            'Content-Type' => 'text/xml; charset=utf-8',
        ], $body);
        if ($status === 404) {
            return null;
        }
        if ($status !== 207) {
            $this->fail($status);
        }

        foreach ($this->parse($content) as $entry) {
            if ($entry->fileId() === $fileId) {
                return $entry;
            }
        }

        return null;
    }

    /** @return list<NextcloudEntry> */
    public function children(string $base, string $user, string $password, string $folderPath): array
    {
        $folderPath = trim($folderPath, '/');
        $children = [];
        foreach ($this->propfind($base, $user, $password, $folderPath, '1') as $entry) {
            if ($this->directChild($folderPath, $entry->path()) === null) {
                continue;
            }
            $children[] = $entry;
        }
        usort($children, static fn (NextcloudEntry $a, NextcloudEntry $b): int => strnatcasecmp($a->name(), $b->name()));

        return $children;
    }

    public function ensureDirectory(string $base, string $user, string $password, string $path): void
    {
        $status = $this->status('MKCOL', $this->filesUrl($base, $user, $path), $user, $password, [], null);
        if ($status === 201 || $status === 405) {
            return;
        }
        $this->fail($status);
    }

    public function move(string $base, string $user, string $password, string $sourcePath, string $destinationPath): void
    {
        $status = $this->status('MOVE', $this->filesUrl($base, $user, $sourcePath), $user, $password, [
            'Destination' => $this->filesUrl($base, $user, $destinationPath),
            'Overwrite' => 'F',
        ], null);
        if ($status === 201 || $status === 204) {
            return;
        }
        $this->fail($status);
    }

    public function upload(string $base, string $user, string $password, string $folderPath, string $filename, string $contents): void
    {
        $folderPath = trim($folderPath, '/');
        $filename = $this->plainFileName($filename);
        if ($folderPath === '' || str_contains($folderPath, '\\') || str_contains($folderPath, '..')) {
            throw new InvalidRequest(['file' => 'Der Ordnername ist ungültig.'], 'Der Ordnername ist ungültig.');
        }
        $status = $this->status('PUT', $this->filesUrl($base, $user, $folderPath.'/'.$filename), $user, $password, [
            'Content-Type' => 'application/octet-stream',
        ], $contents);
        if ($status === 201 || $status === 204) {
            return;
        }
        $this->fail($status);
    }

    public function fileUrl(string $base, string $fileId, string $directory, bool $openFile = false): string
    {
        if (preg_match('/^[0-9]{1,20}$/', $fileId) !== 1) {
            throw new UpstreamUnavailable('Nextcloud hat keine Datei-Kennung geliefert.');
        }
        $this->guard($base);
        $url = $this->origin($base).'/apps/files/files/'.$fileId.'?dir='.$this->filesDir($directory);
        if ($openFile) {
            $url .= '&openfile=true';
        }

        return $url;
    }

    /** @return list<NextcloudEntry> */
    private function propfind(string $base, string $user, string $password, string $path, string $depth): array
    {
        [$status, $content] = $this->exchange('PROPFIND', $this->filesUrl($base, $user, $path), $user, $password, [
            'Depth' => $depth,
            'Content-Type' => 'application/xml; charset=utf-8',
        ], self::PROPFIND);
        if ($status === 404) {
            throw new NextcloudNotFound();
        }
        if ($status !== 207) {
            $this->fail($status);
        }

        return $this->parse($content);
    }

    /** @param list<NextcloudEntry> $entries */
    private function one(array $entries, string $path): ?NextcloudEntry
    {
        $path = trim($path, '/');
        foreach ($entries as $entry) {
            if ($entry->path() === $path) {
                return $entry;
            }
        }

        return $entries[0] ?? null;
    }

    private function assertFolderName(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new InvalidRequest(['name' => 'Der Ordnername ist ungültig.']);
        }
    }

    private function directChild(string $parent, string $path): ?string
    {
        $parent = trim($parent, '/');
        $path = trim($path, '/');
        $prefix = $parent.'/';
        if (!str_starts_with($path, $prefix)) {
            return null;
        }
        $relative = substr($path, strlen($prefix));
        if ($relative === '' || str_contains($relative, '/')) {
            return null;
        }

        return $relative;
    }

    private function status(string $method, string $url, string $user, string $password, array $headers, ?string $body): int
    {
        [$status] = $this->exchange($method, $url, $user, $password, $headers, $body);

        return $status;
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: string}
     */
    private function exchange(string $method, string $url, string $user, string $password, array $headers, ?string $body): array
    {
        $this->guard($url);
        $headers['Authorization'] = $this->basic($user, $password);
        $options = [
            'headers' => $headers,
            'max_redirects' => 0,
            'timeout' => 8,
        ];
        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (\Throwable) {
            throw new UpstreamUnavailable('Nextcloud antwortet gerade nicht.');
        }

        return [$status, $content];
    }

    /** @return list<NextcloudEntry> */
    private function parse(string $xml): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($loaded !== true) {
            throw new UpstreamUnavailable('Nextcloud antwortet gerade nicht.');
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('oc', 'http://owncloud.org/ns');
        $nodes = $xpath->query('//d:response');
        if ($nodes === false) {
            return [];
        }
        $entries = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMNode) {
                continue;
            }
            $href = trim($xpath->evaluate('string(d:href)', $node));
            $path = $this->pathFromHref($href);
            if ($path === null) {
                continue;
            }
            $name = $this->text($xpath, $node, './/d:displayname');
            if ($name === '') {
                $name = basename($path);
            }
            $fileId = $this->numericFileId($this->text($xpath, $node, './/oc:fileid'));
            $ocId = $this->text($xpath, $node, './/oc:id');
            if ($ocId !== '' && preg_match('/^[0-9]+oc[A-Za-z0-9]+$/', $ocId) !== 1) {
                $ocId = '';
            }
            $collection = $xpath->evaluate('count(d:propstat/d:prop/d:resourcetype/d:collection)', $node) > 0;
            $entries[] = new NextcloudEntry($fileId, $ocId !== '' ? $ocId : null, $name, $path, $collection);
        }

        return $entries;
    }

    private function text(\DOMXPath $xpath, \DOMNode $node, string $expression): string
    {
        $nodes = $xpath->query($expression, $node);
        if ($nodes === false) {
            return '';
        }
        foreach ($nodes as $match) {
            $value = trim($match->textContent);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function pathFromHref(string $href): ?string
    {
        $parts = parse_url($href);
        $path = rawurldecode(is_array($parts) ? (string) ($parts['path'] ?? '') : $href);
        if (preg_match('#/remote\.php/dav/files/[^/]+/(.*)$#u', $path, $match) !== 1) {
            return null;
        }
        $relative = trim($match[1], '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        return $relative;
    }

    private function numericFileId(string $value): string
    {
        if (preg_match('/^[0-9]{1,20}$/', $value) === 1) {
            $trimmed = ltrim($value, '0');

            return $trimmed === '' ? '0' : $trimmed;
        }
        if (preg_match('/^0*([0-9]+)oc[A-Za-z0-9]+$/', $value, $match) === 1) {
            return $match[1];
        }

        return '';
    }

    private function plainFileName(string $name): string
    {
        $name = str_replace('\\', '/', trim($name));
        $slash = strrpos($name, '/');
        if ($slash !== false) {
            $name = substr($name, $slash + 1);
        }
        $name = trim($name);
        if ($name === '') {
            throw new InvalidRequest(['file' => 'Bitte eine Datei wählen.'], 'Bitte eine Datei wählen.');
        }
        if ($name === '.' || $name === '..' || str_contains($name, '/') || strpbrk($name, "\0\r\n") !== false || mb_strlen($name) > 255) {
            throw new InvalidRequest(['file' => 'Der Dateiname ist ungültig.'], 'Der Dateiname ist ungültig.');
        }

        return $name;
    }

    private function filesDir(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, "\0")) {
            throw new UpstreamUnavailable('Nextcloud hat keinen Ordnerpfad geliefert.');
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new UpstreamUnavailable('Nextcloud hat keinen Ordnerpfad geliefert.');
            }
            $segments[] = $this->encodeUriComponent($segment);
        }

        return '/'.implode('/', $segments);
    }

    private function encodeUriComponent(string $value): string
    {
        return str_replace(
            ['%21', '%27', '%28', '%29', '%2A', '%7E'],
            ['!', "'", '(', ')', '*', '~'],
            rawurlencode($value),
        );
    }

    private function origin(string $base): string
    {
        $parts = parse_url($base);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $port = is_array($parts) && isset($parts['port']) ? ':'.$parts['port'] : '';

        return 'https://'.$host.$port;
    }

    private function filesUrl(string $base, string $user, string $path): string
    {
        $this->guard($base);
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidRequest(['name' => 'Der Ordnername ist ungültig.']);
            }
            $segments[] = rawurlencode($segment);
        }

        return rtrim($base, '/').'/remote.php/dav/files/'.$this->encodedUser($user).'/'.implode('/', $segments);
    }

    private function davUrl(string $base): string
    {
        $this->guard($base);

        return rtrim($base, '/').'/remote.php/dav/';
    }

    private function encodedUser(string $user): string
    {
        return rawurlencode($user);
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function basic(string $user, string $password): string
    {
        if ($user === '' || $password === '' || strpbrk($user.$password, "\r\n\0") !== false) {
            throw new NextcloudRejected();
        }

        return 'Basic '.base64_encode($user.':'.$password);
    }

    private function guard(string $url): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? (string) ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if ($scheme !== 'https' || !is_string($host) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new NextcloudBaseInvalid();
        }
        if ($this->environment === 'test' && $host !== 'nextcloud.test') {
            throw new UpstreamUnavailable('Tests fragen Nextcloud nicht ab.');
        }
    }

    private function fail(int $status): never
    {
        if ($status === 401 || $status === 403) {
            throw new NextcloudRejected();
        }
        if ($status === 404) {
            throw new NextcloudNotFound();
        }

        throw new UpstreamUnavailable('Nextcloud antwortet gerade nicht.');
    }
}
