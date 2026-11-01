<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Src\Customer\Adapters\EloquentCustomerDirectory;
use Src\Finance\Cash\Adapters\CashClosuresReportSource;
use Src\Finance\Cash\Adapters\CashKpiSource;
use Src\Finance\Cash\Jobs\GenerateCashCloseReportJob;
use Src\Finance\Invoice\Adapters\FakeSunatGateway;
use Src\Finance\Invoice\Contracts\SunatGateway;
use Src\Finance\Invoice\Jobs\GenerateCreditNoteArtifactsJob;
use Src\Finance\Invoice\Jobs\GenerateInvoiceJob;
use Src\Finance\Invoice\Jobs\VoidInvoiceJob;
use Src\Finance\Payment\Adapters\EloquentPaymentStatusQuery;
use Src\Finance\Payment\Adapters\PaymentsKpiSource;
use Src\Finance\Payment\Adapters\RevenueReportSource;
use Src\Finance\Payment\Jobs\MarkBillingOrderDeliveredJob;
use Src\Finance\Payment\Jobs\ProjectBillingOrderCancelledJob;
use Src\Finance\Payment\Jobs\ProjectBillingOrderCreatedJob;
use Src\Fleet\Route\Adapters\EloquentRouteDirectory;
use Src\Fleet\Route\Adapters\FleetConsumptionReportSource;
use Src\Fleet\Route\Adapters\FleetKpiSource;
use Src\Identity\Attendance\Models\AttendanceRecord;
use Src\Identity\Attendance\Policies\AttendancePolicy;
use Src\Identity\Device\Adapters\EloquentDeviceRegistrar;
use Src\Identity\Employee\Adapters\EloquentEmployeeDirectory;
use Src\Identity\Employee\Models\Employee;
use Src\Identity\Employee\Policies\EmployeePolicy;
use Src\Identity\User\Adapters\EloquentUserDirectory;
use Src\Incident\Adapters\IncidentsKpiSource;
use Src\Incident\Adapters\IncidentsReportSource;
use Src\Logistics\Location\Adapters\EloquentCoverageDirectory;
use Src\Logistics\Location\Adapters\EloquentLocationDirectory;
use Src\Logistics\Order\Adapters\EloquentCustomerOrderQuery;
use Src\Logistics\Order\Adapters\EloquentOrderQuery;
use Src\Logistics\Order\Adapters\OrdersKpiSource;
use Src\Logistics\Order\Adapters\OrdersReportSource;
use Src\Logistics\Order\Jobs\GenerateOrderGuidePdfJob;
use Src\Logistics\Order\Jobs\MarkOrderDeliveredJob;
use Src\Logistics\Order\Jobs\MarkOrderInTransitJob;
use Src\Logistics\Order\Jobs\ProjectPaymentRefundedJob;
use Src\Logistics\Order\Jobs\ProjectPaymentRegisteredJob;
use Src\Logistics\Order\Jobs\ProjectPaymentVoidedJob;
use Src\Logistics\Order\Jobs\SendOrderConfirmationMailJob;
use Src\Logistics\Tariff\Adapters\EloquentTariffDirectory;
use Src\Operations\Shipment\Adapters\EloquentShipmentDirectory;
use Src\Operations\Shipment\Adapters\ShipmentsKpiSource;
use Src\Operations\Shipment\Jobs\AlertRouteFinishedWithPendingJob;
use Src\Operations\Shipment\Jobs\CancelShipmentJob;
use Src\Operations\Shipment\Jobs\MarkRouteShipmentsInTransitJob;
use Src\Operations\Shipment\Jobs\ProjectOrderCreatedJob as ProjectShipmentJob;
use Src\Operations\Tracking\Adapters\EloquentTrackingRecorder;
use Src\Operations\Tracking\Adapters\TrackingReportSource;
use Src\Organization\Adapters\EloquentAgencyDirectory;
use Src\Organization\Models\Agency;
use Src\Organization\Policies\AgencyPolicy;
use Src\Reporting\Controllers\DashboardController;
use Src\Reporting\Jobs\GenerateReportExportJob;
use Src\Reporting\Services\ReportCatalog;
use Src\Settings\Adapters\DatabaseSettingsReader;
use Src\Shared\Contracts\AgencyDirectory;
use Src\Shared\Contracts\CoverageDirectory;
use Src\Shared\Contracts\CustomerDirectory;
use Src\Shared\Contracts\CustomerOrderQuery;
use Src\Shared\Contracts\DeviceRegistrar;
use Src\Shared\Contracts\EmployeeDirectory;
use Src\Shared\Contracts\LocationDirectory;
use Src\Shared\Contracts\OrderQuery;
use Src\Shared\Contracts\PaymentStatusQuery;
use Src\Shared\Contracts\RouteDirectory;
use Src\Shared\Contracts\SettingsReader;
use Src\Shared\Contracts\ShipmentDirectory;
use Src\Shared\Contracts\TariffDirectory;
use Src\Shared\Contracts\TrackingRecorder;
use Src\Shared\Contracts\UserDirectory;
use Src\Shared\Jobs\AcknowledgeEventJob;
use Src\Shared\Services\OutboxEventMap;

/**
 * Composition root de los puertos entre contextos.
 *
 * Aquí —y solo aquí— se juntan la interfaz (`Src\Shared\Contracts`) con el
 * adaptador del contexto dueño del modelo. Un contexto nunca importa a otro:
 * depende del puerto y este provider decide quién lo implementa (ADR-0006).
 *
 * Lo mismo vale para el outbox: quién consume cada evento se decide aquí, porque
 * `Shared` no puede conocer los jobs de un contexto.
 */
class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CustomerDirectory::class, EloquentCustomerDirectory::class);
        $this->app->singleton(CustomerOrderQuery::class, EloquentCustomerOrderQuery::class);
        $this->app->singleton(LocationDirectory::class, EloquentLocationDirectory::class);
        $this->app->singleton(CoverageDirectory::class, EloquentCoverageDirectory::class);
        $this->app->singleton(TariffDirectory::class, EloquentTariffDirectory::class);
        $this->app->singleton(AgencyDirectory::class, EloquentAgencyDirectory::class);
        $this->app->singleton(EmployeeDirectory::class, EloquentEmployeeDirectory::class);
        $this->app->singleton(DeviceRegistrar::class, EloquentDeviceRegistrar::class);
        $this->app->singleton(ShipmentDirectory::class, EloquentShipmentDirectory::class);
        $this->app->singleton(TrackingRecorder::class, EloquentTrackingRecorder::class);
        $this->app->singleton(OrderQuery::class, EloquentOrderQuery::class);
        $this->app->singleton(PaymentStatusQuery::class, EloquentPaymentStatusQuery::class);
        $this->app->singleton(UserDirectory::class, EloquentUserDirectory::class);
        $this->app->singleton(RouteDirectory::class, EloquentRouteDirectory::class);
        $this->app->singleton(SettingsReader::class, DatabaseSettingsReader::class);

        // Reportes y dashboard no conocen a los contextos: reciben lo que cada uno
        // aporta. Si un contexto añade un reporte, solo se etiqueta aquí.
        $this->app->tag([
            OrdersReportSource::class,
            RevenueReportSource::class,
            CashClosuresReportSource::class,
            TrackingReportSource::class,
            FleetConsumptionReportSource::class,
            IncidentsReportSource::class,
        ], 'reporting.sources');
        $this->app->tag([
            OrdersKpiSource::class,
            PaymentsKpiSource::class,
            CashKpiSource::class,
            ShipmentsKpiSource::class,
            FleetKpiSource::class,
            IncidentsKpiSource::class,
        ], 'reporting.kpis');
        $this->app->when(ReportCatalog::class)->needs('$sources')->giveTagged('reporting.sources');
        $this->app->when(DashboardController::class)->needs('$sources')->giveTagged('reporting.kpis');

        // Sin caída silenciosa al simulado: un valor desconocido en producción
        // dejaría de emitir comprobantes reales sin que nadie lo note (ADR-0011).
        $this->app->singleton(SunatGateway::class, fn (): SunatGateway => match (config('finance.sunat.gateway')) {
            'fake' => new FakeSunatGateway,
            default => throw new InvalidArgumentException('Unknown SUNAT_GATEWAY: '.config('finance.sunat.gateway')),
        });

        // Policies: los módulos no tienen provider propio (sus rutas y comandos
        // se descubren solos), así que el registro de quién protege qué modelo
        // vive aquí, junto al resto del cableado.
        Gate::policy(Agency::class, AgencyPolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(AttendanceRecord::class, AttendancePolicy::class);

        $this->app->singleton(OutboxEventMap::class, fn (): OutboxEventMap => new OutboxEventMap([
            // `order.created` → Core (PDF, correo) y Operaciones (proyección).
            // Cada job decide su cola: PDF y correo en `default`, proyección en `sync`.
            'order.created' => [
                GenerateOrderGuidePdfJob::class,
                SendOrderConfirmationMailJob::class,
                ProjectShipmentJob::class,
                ProjectBillingOrderCreatedJob::class,
            ],
            // Confirmar una orden todavía no tiene consumidor de negocio: se
            // reconoce para no bloquear el outbox.
            'order.confirmed' => [AcknowledgeEventJob::class],
            // Operaciones consume la cancelación para cerrar el envío si aún
            // no salió a reparto; Finanzas la marca no cobrable.
            'order.cancelled' => [CancelShipmentJob::class, ProjectBillingOrderCancelledJob::class],

            // Eventos de Operaciones. Core mueve la orden con el embarque y la
            // entrega; el resto todavía no tiene consumidor real y se reconoce.
            'shipment.received' => [AcknowledgeEventJob::class],
            'shipment.embarked' => [MarkOrderInTransitJob::class],
            'shipment.disembarked' => [AcknowledgeEventJob::class],
            'inventory.counted' => [AcknowledgeEventJob::class],
            'delivery.completed' => [MarkOrderDeliveredJob::class, MarkBillingOrderDeliveredJob::class],
            'delivery.failed' => [AcknowledgeEventJob::class],

            // Finanzas: Core actualiza su proyección de cobro y el pipeline
            // emite/transmite el comprobante.
            'payment.registered' => [ProjectPaymentRegisteredJob::class, GenerateInvoiceJob::class],
            'payment.refunded' => [ProjectPaymentRefundedJob::class],
            'payment.voided' => [ProjectPaymentVoidedJob::class, VoidInvoiceJob::class],
            'cash.closed' => [GenerateCashCloseReportJob::class],
            'credit-note.issued' => [GenerateCreditNoteArtifactsJob::class],

            // Acuses de documentos fiscales: evitan que un evento publicado sin
            // consumidor de negocio tumbe el outbox.
            'invoice.accepted' => [AcknowledgeEventJob::class],
            'invoice.rejected' => [AcknowledgeEventJob::class],
            'invoice.voided' => [AcknowledgeEventJob::class],
            'invoice.void-rejected' => [AcknowledgeEventJob::class],
            'credit-note.accepted' => [AcknowledgeEventJob::class],
            'credit-note.rejected' => [AcknowledgeEventJob::class],

            // Flota: Operaciones mueve los envíos enlazados a la ruta. El resto
            // alimentará reportes (Fase 7) y mientras tanto se reconoce.
            'route.started' => [MarkRouteShipmentsInTransitJob::class],
            'route.finished' => [AlertRouteFinishedWithPendingJob::class],
            'route.segment-completed' => [AcknowledgeEventJob::class],
            'route.cancelled' => [AcknowledgeEventJob::class],
            'expense.registered' => [AcknowledgeEventJob::class],

            // Reportes: la exportación se genera en la cola `reports`.
            'report.export-requested' => [GenerateReportExportJob::class],
        ]));
    }
}
