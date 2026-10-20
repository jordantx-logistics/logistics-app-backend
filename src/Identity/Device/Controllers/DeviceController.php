<?php

namespace Src\Identity\Device\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Src\Identity\Device\Models\Device;
use Src\Identity\Device\Resources\DeviceResource;
use Src\Identity\Device\UseCases\ListDevicesUseCase;
use Src\Identity\Device\UseCases\RevokeDeviceUseCase;

/**
 * @group Identity - Devices
 *
 * APIs para listar y revocar dispositivos con token (UC-19).
 */
class DeviceController extends Controller
{
    /**
     * List Devices
     *
     * Lista los dispositivos del usuario (o los de otro usuario con `user_id` si se tiene `devices.manage`).
     *
     * @queryParam user_id integer Dispositivos de otro usuario (requiere `devices.manage`). Example: 10
     * @queryParam status string active,revoked. Example: active
     * @queryParam per_page integer Rows per page (1..100, por defecto 15). Example: 15
     *
     * @apiResourceCollection Src\Identity\Device\Resources\DeviceResource
     *
     * @apiResourceModel Src\Identity\Device\Models\Device paginate=15
     *
     * @apiResourceAdditional success=true message="Devices retrieved successfully."
     */
    public function index(Request $request, ListDevicesUseCase $useCase): JsonResponse
    {
        $perPage = min(
            max($request->integer('per_page', ListDevicesUseCase::DEFAULT_PER_PAGE), 1),
            ListDevicesUseCase::MAX_PER_PAGE,
        );

        $devices = $useCase->execute(
            $request->user(),
            $request->only(['user_id', 'status']),
            $perPage,
        );

        return $this->successPaginated(
            resource: DeviceResource::collection($devices),
            message: 'Devices retrieved successfully.',
        );
    }

    /**
     * Revoke Device
     *
     * Revoca el dispositivo y sus tokens: la siguiente petición con ese token responde `401`.
     *
     * @response 200 {"success": true, "message": "Device revoked successfully."}
     */
    public function destroy(Device $device, RevokeDeviceUseCase $useCase): JsonResponse
    {
        $useCase->execute($device);

        return $this->success(message: 'Device revoked successfully.');
    }
}
