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

        $payload = StreamedExports::peek($token);
        $isOwner = $payload !== null && $this->isOwner($payload);

        if ($isOwner) {
            $this->restoreLocale($payload);
        }

        if (!URL::signatureHasNotExpired($request)) {
            return $this->refuse($payload, 'link_expired');
        }

        // Another user (or a guest) must not burn the owner's link: refuse before spending.
        if ($payload === null || !$isOwner) {
            return $this->refuse($payload, 'link_unusable');
        }

        // A table outside any panel stores no panel id: it gets no panel here either, rather than
        // the default one (an unrelated panel, or an exception when none is marked default).
        $panel = null;

        if ($payload['panel'] !== null) {
            try {
                $panel = Filament::getPanel($payload['panel']);
            } catch (Throwable) {
                return $this->refuse($payload, 'link_unusable');
            }

            Filament::setCurrentPanel($panel);
        }

        // Defence in depth: the action never issues a link on a tenant panel (see
        // StreamedExports::supportsPanel()); a token stored by an older version might still exist.
        if (!StreamedExports::supportsPanel($panel)) {
            return $this->refuse($payload, 'link_unusable');
        }

        if (!StreamedExports::spend($token)) {
            return $this->refuse($payload, 'link_unusable');
        }

        if ($panel !== null) {
            // What the panel's own SetUpPanel middleware does: the panel and its plugins register
            // their global scopes, render hooks and so on in boot().
            Filament::bootCurrentPanel();
        }

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
     * @param array<string, mixed> $payload
     */
    private function isOwner(array $payload): bool
    {
        $guard = $payload['guard'] ?? null;

        try {
            $user = Auth::guard(is_string($guard) ? $guard : null)->id();
        } catch (Throwable) {
            return false;
        }

        return $user !== null && (string) $user === (string) $payload['user'];
    }

    /**
     * The download request does not run the panel's middleware, where a panel usually sets the
     * user's locale. Rehydrating the component restores the snapshot's locale (Livewire's
     * SupportLocales hook), but only for the file: the refusal notifications come before that.
     * Restoring it up front keeps them in the language of the page that asked for the file.
     *
     * @param array<string, mixed> $payload
     */
    private function restoreLocale(array $payload): void
    {
        $locale = $payload['locale'] ?? null;

        if (is_string($locale) && $locale !== '') {
            app()->setLocale($locale);
        }
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
