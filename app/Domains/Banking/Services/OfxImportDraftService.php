<?php

namespace App\Domains\Banking\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class OfxImportDraftService
{
    public const SESSION_KEY = 'banking.ofx_preview';

    private const DIRECTORY = 'banking/ofx-previews';

    public function create(int $accountId, string $contents): void
    {
        $this->clear();
        $this->deleteExpiredFiles();

        $path = self::DIRECTORY.'/'.Str::uuid().'.ofx';

        if (! Storage::disk('local')->put($path, $contents)) {
            throw new RuntimeException('Não foi possível preparar a prévia do OFX. Tente novamente.');
        }

        session()->put(self::SESSION_KEY, [
            'user_id' => auth()->id(),
            'account_id' => $accountId,
            'path' => $path,
            'hash' => hash('sha256', $contents),
            'expires_at' => now()->addDay()->timestamp,
            'categories' => [],
            'excluded' => [],
        ]);
    }

    /** @return array{account_id: int, contents: string, categories: array, excluded: array}|null */
    public function current(): ?array
    {
        $draft = session(self::SESSION_KEY);

        if (! is_array($draft) || ($draft['user_id'] ?? null) !== auth()->id()
            || ($draft['expires_at'] ?? 0) < now()->timestamp
            || ! $this->isSafePath($draft['path'] ?? null)
            || ! Storage::disk('local')->exists($draft['path'])) {
            $this->clear();

            return null;
        }

        $contents = Storage::disk('local')->get($draft['path']);

        if (! is_string($contents) || ! hash_equals($draft['hash'] ?? '', hash('sha256', $contents))) {
            $this->clear();

            return null;
        }

        return [
            'account_id' => (int) $draft['account_id'],
            'contents' => $contents,
            'categories' => $draft['categories'] ?? [],
            'excluded' => $draft['excluded'] ?? [],
        ];
    }

    public function saveReview(array $categories, array $excluded): void
    {
        if ($this->current()) {
            session()->put(self::SESSION_KEY.'.categories', $categories);
            session()->put(self::SESSION_KEY.'.excluded', $excluded);
        }
    }

    public function clear(): void
    {
        $path = session(self::SESSION_KEY)['path'] ?? null;

        if ($this->isSafePath($path)) {
            Storage::disk('local')->delete($path);
        }

        session()->forget(self::SESSION_KEY);
    }

    private function isSafePath(mixed $path): bool
    {
        return is_string($path)
            && (bool) preg_match('~^'.self::DIRECTORY.'/[0-9a-f-]{36}\.ofx$~', $path);
    }

    private function deleteExpiredFiles(): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDay()->timestamp;

        foreach ($disk->files(self::DIRECTORY) as $path) {
            if ($this->isSafePath($path) && $disk->lastModified($path) < $cutoff) {
                $disk->delete($path);
            }
        }
    }
}
