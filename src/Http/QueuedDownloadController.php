<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Http;

use Asignua\FilamentXlsxExport\Support\QueuedExports;
use Asignua\FilamentXlsxExport\XlsxExporter;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Streams a file a queued export has finished. Reached through the signed link in the user's
 * notification, by the user the export belongs to; unlike the streaming route the link is not
 * single-use (a notification may be clicked twice) and lives as long as the file does.
 *
 * Same refusal manners as `DownloadController`: a forged signature is a 403, anything a human can
 * run into (expired, somebody else's, pruned) sends them back with a notification.
 */
final class QueuedDownloadController
{
    public function __invoke(Request $request, string $id): Response
    {
        abort_unless(URL::hasCorrectSignature($request), 403);

        if (!URL::signatureHasNotExpired($request)) {
            return $this->refuse(null, 'link_expired');
        }

        $meta = QueuedExports::meta($id);

        if ($meta === null) {
            return $this->refuse(null, 'file_gone');
        }

        // A file a plugin stored with a time limit is gone after it, whatever the retention says (retention 0 keeps the rest for good).
        if (isset($meta['keep_until']) && $meta['keep_until'] < now()->getTimestamp()) {
            return $this->refuse($meta['panel'], 'link_expired');
        }

        // Somebody else (or a guest) gets the same answer as for a missing file: the link says
        // nothing about who the export belongs to.
        if (!$this->isOwner($meta) || !$this->mayOpenPanel($meta) || !$this->mayDownload($meta)) {
            return $this->refuse($meta['panel'], 'link_unusable');
        }

        return QueuedExports::disk()->download(
            QueuedExports::filePath($id),
            XlsxExporter::safeFilename($meta['file_name']),
            ['Content-Type' => XlsxExporter::CONTENT_TYPE],
        );
    }

    /**
     * @param array{user: int|string|null, guard: string|null} $meta
     */
    private function isOwner(array $meta): bool
    {
        try {
            $user = Auth::guard($meta['guard'])->id();
        } catch (Throwable) {
            return false;
        }

        return $user !== null && (string) $user === (string) $meta['user'];
    }

    /**
     * The check of the plugin that stored the file (a scheduled report: is the owner still allowed to schedule it?).
     *
     * @param array{user: int|string|null, guard: string|null, scope?: string, scope_data?: array<string, mixed>} $meta
     */
    private function mayDownload(array $meta): bool
    {
        try {
            $user = Auth::guard($meta['guard'])->user();

            return $user !== null && QueuedExports::mayDownload($meta, $user);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The link outlives the click by hours: somebody who lost the panel meanwhile gets nothing.
     *
     * @param array{guard: string|null, panel: string|null} $meta
     */
    private function mayOpenPanel(array $meta): bool
    {
        if ($meta['panel'] === null) {
            return true;
        }

        try {
            $user = Auth::guard($meta['guard'])->user();

            if (!$user instanceof FilamentUser) {
                return true;
            }

            $target = Filament::getPanel($meta['panel']);

            return $target !== null && $user->canAccessPanel($target);
        } catch (Throwable) {
            return false;
        }
    }

    private function refuse(?string $panelId, string $message): RedirectResponse
    {
        Notification::make()
            ->title(__('filament-xlsx-export::xlsx-export.'.$message))
            ->danger()
            ->send();

        try {
            $target = $panelId !== null ? Filament::getPanel($panelId) : Filament::getDefaultPanel();
            $fallback = url($target?->getPath() ?? '/');
        } catch (Throwable) {
            $fallback = url('/');
        }

        return redirect()->back(302, [], $fallback);
    }
}
