<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxExport\Http;

use Asignua\FilamentXlsxExport\Support\StreamedExports;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a prepared export. Reached only through a temporary signed URL (the `signed`
 * middleware), by the user it was issued to, once.
 */
final class DownloadController
{
    public function __invoke(string $token): StreamedResponse
    {
        $payload = StreamedExports::peek($token);

        abort_if($payload === null, 410);

        $user = Auth::guard($payload['guard'])->id();

        abort_unless($user !== null && (string) $user === (string) $payload['user'], 403);
        abort_unless(StreamedExports::spend($token), 410);

        if ($payload['panel'] !== null) {
            Filament::setCurrentPanel($payload['panel']);
        }

        $component = StreamedExports::rehydrate($payload['snapshot']);
        $action = StreamedExports::findAction($component, $payload['action'], $payload['bulk']);

        abort_if($action === null || !method_exists($action, 'streamExport'), 410);

        $query = $payload['bulk']
            ? $component->getSelectedTableRecordsQuery()
            : $component->getFilteredSortedTableQuery();

        abort_if($query === null, 410);

        /** @var StreamedResponse */
        return $action->streamExport($component, $query, $payload['data']);
    }
}
