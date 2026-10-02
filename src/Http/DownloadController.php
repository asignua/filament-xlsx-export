<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Http;

use Asignua\FilamentXlsxExport\Support\StreamedExports;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Streams a prepared export. Reached only through a temporary signed URL, by the user it was
 * issued to, once.
 *
 * The signature is checked here rather than only by route middleware, so the check cannot be
 * lost on a custom route, and so an expired link can send the user back to the panel with a
 * message instead of a bare error page. A forged or altered link still gets a 403.
 */
final class DownloadController
{
    public function __invoke(Request $request, string $token): Response
    {
        abort_unless(URL::hasCorrectSignature($request), 403);

        if (!URL::signatureHasNotExpired($request)) {
            return $this->refuse(StreamedExports::peek($token), 'link_expired');
        }

        $payload = StreamedExports::peek($token);

        if ($payload === null) {
            return $this->refuse(null, 'link_unusable');
        }

        $user = Auth::guard($payload['guard'])->id();

        // Another user (or a guest) must not burn the owner's link: refuse before spending.
        if ($user === null || (string) $user !== (string) $payload['user']) {
            return $this->refuse($payload, 'link_unusable');
        }

        if ($payload['panel'] !== null) {
            Filament::setCurrentPanel($payload['panel']);
        }

        // Defence in depth: the action never issues a link on a tenant panel (see
        // StreamedExports::supportsPanel()); a token stored by an older version might still exist.
        if (!StreamedExports::supportsPanel(Filament::getCurrentPanel())) {
            return $this->refuse($payload, 'link_unusable');
        }

        if (!StreamedExports::spend($token)) {
            return $this->refuse($payload, 'link_unusable');
        }

        // What the panel's own SetUpPanel middleware does: the panel and its plugins register
        // their global scopes, render hooks and so on in boot().
        Filament::bootCurrentPanel();

        try {
            $component = StreamedExports::rehydrate($payload['snapshot']);
            $action = StreamedExports::findAction($component, $payload['action'], $payload['bulk'], $payload['table'] ?? null);
        } catch (Throwable $exception) {
            report($exception);

            return $this->refuse($payload, 'link_unusable');
        }

        if ($action === null || !method_exists($action, 'streamExport')) {
            return $this->refuse($payload, 'link_unusable');
        }

        $query = $payload['bulk']
            ? $component->getSelectedTableRecordsQuery()
            : $component->getFilteredSortedTableQuery();

        if ($query === null) {
            return $this->refuse($payload, 'link_unusable');
        }

        /** @var Response */
        return $action->streamExport($component, $query, $payload['data']);
    }

    /**
     * Back to the page that asked for the file (or the panel), with a notification flashed into
     * the session, instead of leaving the panel for an error page.
     *
     * @param array<string, mixed>|null $payload
     */
    private function refuse(?array $payload, string $message): RedirectResponse
    {
        Notification::make()
            ->title(__('filament-xlsx-export::xlsx-export.'.$message))
            ->danger()
            ->send();

        return redirect()->to($this->backUrl($payload));
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function backUrl(?array $payload): string
    {
        $back = $payload['back'] ?? null;

        if (is_string($back) && $back !== '') {
            return $back;
        }

        $panelId = $payload['panel'] ?? null;

        try {
            $panel = is_string($panelId) ? Filament::getPanel($panelId) : Filament::getDefaultPanel();

            return url($panel->getPath());
        } catch (Throwable) {
            return url('/');
        }
    }
}
