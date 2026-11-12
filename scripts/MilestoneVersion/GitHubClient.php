<?php

namespace Scripts\MilestoneVersion;

use RuntimeException;

/**
 * GitHub REST client. With GITHUB_TOKEN or GH_TOKEN in the environment (as in CI) it calls the API directly;
 * otherwise it goes through the signed-in gh CLI. The token is never printed or stored.
 */
final class GitHubClient implements GitHubWrites
{
    private const PER_PAGE = 100;

    private const DEPLOY_WORKFLOW = 'deploy-cloudron.yml';

    public function __construct(
        private readonly string $repository,
        private readonly ?string $token,
        private readonly string $apiUrl = 'https://api.github.com',
    ) {}

    public static function fromEnvironment(string $workingDirectory): self
    {
        $repository = getenv('GITHUB_REPOSITORY') ?: self::repositoryFromRemote($workingDirectory);
        $token = getenv('GITHUB_TOKEN') ?: (getenv('GH_TOKEN') ?: null);

        return new self($repository, $token, rtrim(getenv('GITHUB_API_URL') ?: 'https://api.github.com', '/'));
    }

    public function openMilestones(): array
    {
        return array_map(
            fn (array $m): array => ['number' => (int) $m['number'], 'title' => (string) $m['title']],
            $this->paginate("repos/{$this->repository}/milestones?state=open"),
        );
    }

    public function tags(): array
    {
        return array_map(fn (array $t): string => (string) $t['name'], $this->paginate("repos/{$this->repository}/tags"));
    }

    public function issue(int $number): ?array
    {
        $issue = $this->get("repos/{$this->repository}/issues/{$number}");

        return $issue === null ? null : [
            'number' => (int) $issue['number'],
            'title' => (string) $issue['title'],
            'milestone' => $issue['milestone']['title'] ?? null,
        ];
    }

    public function pullRequest(int $number): ?array
    {
        $pr = $this->get("repos/{$this->repository}/pulls/{$number}");

        return $pr === null ? null : [
            'number' => (int) $pr['number'],
            'title' => (string) $pr['title'],
            'body' => (string) ($pr['body'] ?? ''),
            'milestone' => $pr['milestone']['title'] ?? null,
        ];
    }

    public function milestoneIssues(int $milestone): array
    {
        $issues = array_filter(
            $this->paginate("repos/{$this->repository}/issues?milestone={$milestone}&state=all"),
            fn (array $i): bool => ! isset($i['pull_request']),
        );

        return array_values(array_map(fn (array $i): array => [
            'number' => (int) $i['number'],
            'title' => (string) $i['title'],
            'open' => $i['state'] === 'open',
            'stateReason' => isset($i['state_reason']) ? (string) $i['state_reason'] : null,
            'openSubIssues' => max(0, (int) ($i['sub_issues_summary']['total'] ?? 0) - (int) ($i['sub_issues_summary']['completed'] ?? 0)),
        ], $issues));
    }

    public function successfulDeployRun(string $tag): ?string
    {
        $runs = $this->get(sprintf(
            'repos/%s/actions/workflows/%s/runs?branch=%s&status=success&per_page=1',
            $this->repository,
            self::DEPLOY_WORKFLOW,
            rawurlencode($tag),
        ));

        return $runs['workflow_runs'][0]['html_url'] ?? null;
    }

    public function commentIssue(int $number, string $body): void
    {
        $this->request('POST', "repos/{$this->repository}/issues/{$number}/comments", ['body' => $body]);
    }

    public function closeIssue(int $number, string $reason): void
    {
        $this->request('PATCH', "repos/{$this->repository}/issues/{$number}", ['state' => 'closed', 'state_reason' => $reason]);
    }

    public function closeMilestone(int $number, string $note): void
    {
        $path = "repos/{$this->repository}/milestones/{$number}";
        $description = rtrim((string) ($this->get($path)['description'] ?? ''));

        $this->request('PATCH', $path, [
            'state' => 'closed',
            'description' => $description === '' ? $note : $description."\n\n".$note,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paginate(string $path): array
    {
        $items = [];
        $separator = str_contains($path, '?') ? '&' : '?';

        for ($page = 1; ; $page++) {
            $batch = $this->get($path.$separator.'per_page='.self::PER_PAGE.'&page='.$page) ?? [];
            array_push($items, ...$batch);
            if (count($batch) < self::PER_PAGE) {
                return $items;
            }
        }
    }

    /**
     * @return ?array<mixed> Decoded JSON, or null when GitHub answers 404.
     */
    private function get(string $path): ?array
    {
        return $this->request('GET', $path);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return ?array<mixed> Decoded JSON, or null when GitHub answers 404.
     */
    private function request(string $method, string $path, ?array $body = null): ?array
    {
        $json = $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        [$status, $response] = $this->token === null ? $this->viaGh($method, $path, $json) : $this->viaHttp($method, $path, $json);

        if ($status === 404) {
            return null;
        }
        if ($status < 200 || $status > 299) {
            throw new RuntimeException(sprintf('GitHub API %s %s failed (HTTP %d).', $method, strtok($path, '?'), $status));
        }

        $decoded = json_decode($response, true);
        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('GitHub API returned invalid JSON for %s.', strtok($path, '?')));
        }

        return $decoded;
    }

    /**
     * @return array{int, string}
     */
    private function viaHttp(string $method, string $path, ?string $json): array
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer '.$this->token,
            'User-Agent: apescic-milestone-version',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $context = stream_context_create(['http' => array_filter([
            'method' => $method,
            'ignore_errors' => true,
            'timeout' => 30,
            'header' => implode("\r\n", $headers),
            'content' => $json,
        ], fn ($value): bool => $value !== null)]);

        $response = @file_get_contents($this->apiUrl.'/'.$path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        if ($response === false || $responseHeaders === [] || preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders[0], $m) !== 1) {
            throw new RuntimeException('Could not reach the GitHub API.');
        }

        return [(int) $m[1], $response];
    }

    /**
     * @return array{int, string}
     */
    private function viaGh(string $method, string $path, ?string $json): array
    {
        $command = ['gh', 'api', '-X', $method, '-H', 'Accept: application/vnd.github+json', $path];
        if ($json !== null) {
            array_push($command, '--input', '-');
        }
        [$exitCode, $stdout, $stderr] = self::run($command, $json);

        if ($exitCode === 0) {
            return [200, $stdout];
        }
        if (str_contains($stderr, 'HTTP 404') || str_contains($stderr, 'Not Found')) {
            return [404, ''];
        }
        if (str_contains($stderr, 'auth login') || str_contains($stderr, 'not logged')) {
            throw new RuntimeException('No GitHub credentials. Set GITHUB_TOKEN, or sign in with gh auth login.');
        }

        throw new RuntimeException('gh api failed: '.trim($stderr));
    }

    private static function repositoryFromRemote(string $workingDirectory): string
    {
        [$exitCode, $stdout] = self::run(['git', '-C', $workingDirectory, 'remote', 'get-url', 'origin']);

        if ($exitCode !== 0 || preg_match('#github\.com[:/]([^/]+/[^/]+?)(?:\.git)?/?$#', trim($stdout), $m) !== 1) {
            throw new RuntimeException('Could not work out the GitHub repository. Set GITHUB_REPOSITORY=owner/name.');
        }

        return $m[1];
    }

    /**
     * @param  list<string>  $command
     * @return array{int, string, string}
     */
    private static function run(array $command, ?string $stdin = null): array
    {
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes); // A fixed gh or git argument list, run without a shell.
        if (! is_resource($process)) {
            throw new RuntimeException(sprintf('Could not run %s. Is it installed and on PATH?', $command[0]));
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
