<?php

namespace SocialPoster\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use SocialPoster\Gateways\FilesystemMediaGateway;
use SocialPoster\Gateways\LocalMediaGateway;
use Throwable;

/**
 * Backstop for temp media. Gateways delete their sp_* downloads in cleanup(),
 * but a worker killed mid-upload (timeout, OOM, deploy) never reaches it, and
 * re-hosted copies must outlive the publish. Schedule it daily:
 *
 *     Schedule::command('social-poster:prune-temp')->daily();
 *
 * Removes, when older than --hours (24):
 * - sp_<16 hex> files in social.temp_dir (downloads, conversions, inspection
 *   ranges), the sp_tiktok_<16 hex> copies TikTok keeps between upload runs
 *   (left behind when the last try fails or the worker is killed), and the
 *   sp_youtube_<16 hex> copies YouTube keeps between upload runs (left behind
 *   by a chain the job ended: deadline, retries exhausted, a killed worker, a
 *   run that moved to another worker host);
 * - with --legacy, v1.0's sp_<16 hex> files in the system temp directory;
 * - files re-hosted by FilesystemMediaGateway under social.media.rehost_prefix
 *   on social.media.rehost_disk, when that gateway is configured (or with
 *   --rehosted); only names the gateway generates (a 40-hex hash) are touched.
 */
class PruneTempMediaCommand extends Command
{
    protected $signature = 'social-poster:prune-temp
        {--hours=24 : Delete files older than this many hours}
        {--rehosted : Also prune re-hosted copies even when FilesystemMediaGateway is not the configured gateway}
        {--skip-rehosted : Never prune re-hosted copies}
        {--legacy : Also prune v1.0 sp_* files in the system temp directory}
        {--dry-run : List what would be deleted without deleting it}';

    protected $description = 'Delete stale social-poster temp media and expired re-hosted copies';

    public function handle(): int
    {
        $hours = max(0, (float) $this->option('hours'));
        $cutoff = time() - (int) round($hours * 3600);
        $dryRun = (bool) $this->option('dry-run');

        $deleted = $this->pruneDirectory(LocalMediaGateway::temporaryDirectory(), '/^sp_(?:tiktok_|youtube_)?[0-9a-f]{16}(\.[a-z0-9]+)?$/', $cutoff, $dryRun);

        if ($this->option('legacy')) {
            $deleted += $this->pruneDirectory(sys_get_temp_dir(), '/^sp_[0-9a-f]{16}(\.[a-z0-9]+)?$/', $cutoff, $dryRun);
        }

        if ($this->shouldPruneRehosted()) {
            $deleted += $this->pruneRehosted($cutoff, $dryRun);
        }

        $this->info(($dryRun ? 'Would delete ' : 'Deleted ').$deleted.' file(s).');

        return self::SUCCESS;
    }

    protected function pruneDirectory(string $dir, string $pattern, int $cutoff, bool $dryRun): int
    {
        $count = 0;

        foreach (@scandir($dir) ?: [] as $name) {
            $file = $dir.DIRECTORY_SEPARATOR.$name;

            if (preg_match($pattern, $name) !== 1 || ! is_file($file) || (int) @filemtime($file) > $cutoff) {
                continue;
            }

            if ($dryRun || @unlink($file)) {
                $count++;
                $this->line(($dryRun ? 'would delete ' : 'deleted ').$file, verbosity: 'v');
            }
        }

        return $count;
    }

    protected function pruneRehosted(int $cutoff, bool $dryRun): int
    {
        $gateway = new FilesystemMediaGateway;
        $disk = Storage::disk($gateway->rehostDisk());
        $count = 0;

        try {
            $files = $disk->files($gateway->rehostPrefix(), true);
        } catch (Throwable $e) {
            $this->warn("Could not list the re-hosted media: {$e->getMessage()}");

            return 0;
        }

        foreach ($files as $file) {
            if (preg_match('/^[0-9a-f]{40}(\.[a-z0-9]+)?$/', basename($file)) !== 1) {
                continue;
            }

            try {
                if ($disk->lastModified($file) > $cutoff) {
                    continue;
                }

                if ($dryRun || $disk->delete($file)) {
                    $count++;
                    $this->line(($dryRun ? 'would delete ' : 'deleted ').$file, verbosity: 'v');
                }
            } catch (Throwable) {
                // Keep going: one unreadable object must not stop the prune.
            }
        }

        return $count;
    }

    protected function shouldPruneRehosted(): bool
    {
        if ($this->option('skip-rehosted')) {
            return false;
        }

        $gateway = config('social.gateway');

        return $this->option('rehosted')
            || (is_string($gateway) && is_a($gateway, FilesystemMediaGateway::class, true));
    }
}
