<?php

namespace Src\Operations\Delivery\UseCases;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Src\Operations\Delivery\Models\Delivery;
use Src\Shared\Exceptions\BusinessRuleException;
use Src\Shared\Scope\AgencyScope;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Firma y foto de una entrega en el disco `OPERATIONS_DISK`. La ruta depende solo de
 * la entrega y el tipo: un reintento de subida reemplaza el archivo y no acumula.
 */
class DeliveryEvidenceStore
{
    public const TYPE_SIGNATURE = 'signature';

    public const TYPE_PHOTO = 'photo';

    public const TYPES = [self::TYPE_SIGNATURE, self::TYPE_PHOTO];

    public function store(int $deliveryId, string $type, UploadedFile $file, AgencyScope $scope): string
    {
        $this->visible($deliveryId, $scope);

        $path = sprintf('deliveries/%d/%s.%s', $deliveryId, $type, $file->extension());

        Storage::disk($this->disk())->put($path, $file->getContent());

        return $path;
    }

    public function download(int $deliveryId, string $type, AgencyScope $scope): StreamedResponse
    {
        $evidence = $this->visible($deliveryId, $scope)->evidence;
        $path = $type === self::TYPE_SIGNATURE ? $evidence?->signature_path : $evidence?->photo_path;

        if ($path === null || ! Storage::disk($this->disk())->exists($path)) {
            throw new BusinessRuleException(
                message: 'The evidence file is not available.',
                errorCode: 'DELIVERY_EVIDENCE_NOT_FOUND',
                statusCode: 404,
            );
        }

        return Storage::disk($this->disk())->download($path);
    }

    private function visible(int $deliveryId, AgencyScope $scope): Delivery
    {
        $delivery = Delivery::query()->forAgencyScope($scope)->find($deliveryId);

        if ($delivery === null) {
            throw new BusinessRuleException(
                message: 'The delivery does not exist.',
                errorCode: 'DELIVERY_NOT_FOUND',
                statusCode: 404,
            );
        }

        return $delivery;
    }

    private function disk(): string
    {
        return (string) config('operations.disk', 'local');
    }
}
