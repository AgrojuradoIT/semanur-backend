<?php

namespace Tests\Unit\InventoryOrders;

use App\Domain\InventoryOrders\LineaEstado;
use App\Domain\InventoryOrders\NovedadEstado;
use App\Domain\InventoryOrders\OcrIntentoEstado;
use App\Domain\InventoryOrders\PedidoEstado;
use App\Domain\InventoryOrders\RecepcionEstado;
use PHPUnit\Framework\TestCase;

class PedidoEstadosTest extends TestCase
{
    public function test_pedido_whitelist_matches_section_6(): void
    {
        $this->assertTrue(PedidoEstado::permiteTransicion(PedidoEstado::BORRADOR, PedidoEstado::PENDIENTE_RECEPCION));
        $this->assertTrue(PedidoEstado::permiteTransicion(PedidoEstado::BORRADOR, PedidoEstado::CANCELADO));
        $this->assertFalse(PedidoEstado::permiteTransicion(PedidoEstado::BORRADOR, PedidoEstado::RECIBIDO));

        $this->assertTrue(PedidoEstado::permiteTransicion(PedidoEstado::PENDIENTE_RECEPCION, PedidoEstado::EN_VALIDACION));
        $this->assertTrue(PedidoEstado::permiteTransicion(PedidoEstado::PENDIENTE_RECEPCION, PedidoEstado::BORRADOR));
        $this->assertTrue(PedidoEstado::permiteTransicion(PedidoEstado::PENDIENTE_RECEPCION, PedidoEstado::CANCELADO));

        $this->assertTrue(PedidoEstado::permiteTransicion(PedidoEstado::EN_VALIDACION, PedidoEstado::RECIBIDO));
        $this->assertTrue(PedidoEstado::permiteTransicion(PedidoEstado::EN_VALIDACION, PedidoEstado::RECIBIDO_CON_DESFASE));
        $this->assertTrue(PedidoEstado::permiteTransicion(PedidoEstado::EN_VALIDACION, PedidoEstado::PARCIAL_CON_NOVEDAD));

        $this->assertTrue(PedidoEstado::permiteTransicion(PedidoEstado::PARCIAL_CON_NOVEDAD, PedidoEstado::PARCIAL_CON_NOVEDAD));
        $this->assertTrue(PedidoEstado::permiteTransicion(PedidoEstado::PARCIAL_CON_NOVEDAD, PedidoEstado::RECIBIDO));
        $this->assertFalse(PedidoEstado::permiteTransicion(PedidoEstado::PARCIAL_CON_NOVEDAD, PedidoEstado::CANCELADO));
    }

    public function test_terminal_pedido_states_do_not_transition(): void
    {
        foreach ([PedidoEstado::RECIBIDO, PedidoEstado::RECIBIDO_CON_DESFASE, PedidoEstado::CANCELADO] as $estado) {
            $this->assertTrue(PedidoEstado::esTerminal($estado));
            $this->assertSame([], PedidoEstado::transicionesPermitidas($estado));
        }

        $this->assertFalse(PedidoEstado::existe('estado_inventado'));
        $this->assertFalse(PedidoEstado::esEditable(PedidoEstado::PENDIENTE_RECEPCION));
        $this->assertTrue(PedidoEstado::esperadoCongelado(PedidoEstado::PENDIENTE_RECEPCION));
    }

    public function test_linea_whitelist(): void
    {
        $this->assertTrue(LineaEstado::permiteTransicion(LineaEstado::PENDIENTE, LineaEstado::CONFORME));
        $this->assertTrue(LineaEstado::permiteTransicion(LineaEstado::PENDIENTE, LineaEstado::NOVEDAD));
        $this->assertFalse(LineaEstado::permiteTransicion(LineaEstado::PENDIENTE, LineaEstado::RECIBIDA));

        $this->assertTrue(LineaEstado::permiteTransicion(LineaEstado::CONFORME, LineaEstado::NOVEDAD));
        $this->assertTrue(LineaEstado::permiteTransicion(LineaEstado::CONFORME, LineaEstado::RECIBIDA));
        $this->assertTrue(LineaEstado::permiteTransicion(LineaEstado::NOVEDAD, LineaEstado::CONFORME));
        $this->assertTrue(LineaEstado::permiteTransicion(LineaEstado::NOVEDAD, LineaEstado::RECIBIDA_CON_DESFASE));
        $this->assertTrue(LineaEstado::permiteTransicion(LineaEstado::NOVEDAD, LineaEstado::CIERRE_SIN_INGRESO));

        $this->assertTrue(LineaEstado::esTerminal(LineaEstado::RECIBIDA));
        $this->assertTrue(LineaEstado::esTerminal(LineaEstado::RECIBIDA_CON_DESFASE));
        $this->assertTrue(LineaEstado::esTerminal(LineaEstado::CIERRE_SIN_INGRESO));
    }

    public function test_ocr_attempt_whitelist(): void
    {
        $this->assertTrue(OcrIntentoEstado::permiteTransicion(OcrIntentoEstado::PENDIENTE, OcrIntentoEstado::PROCESANDO));
        $this->assertTrue(OcrIntentoEstado::permiteTransicion(OcrIntentoEstado::PENDIENTE, OcrIntentoEstado::CANCELADO));
        $this->assertTrue(OcrIntentoEstado::permiteTransicion(OcrIntentoEstado::PROCESANDO, OcrIntentoEstado::COMPLETADO));
        $this->assertTrue(OcrIntentoEstado::permiteTransicion(OcrIntentoEstado::PROCESANDO, OcrIntentoEstado::REQUIERE_REVISION));
        $this->assertTrue(OcrIntentoEstado::permiteTransicion(OcrIntentoEstado::PROCESANDO, OcrIntentoEstado::FALLIDO));
        $this->assertTrue(OcrIntentoEstado::permiteTransicion(OcrIntentoEstado::COMPLETADO, OcrIntentoEstado::OBSOLETO));
        $this->assertFalse(OcrIntentoEstado::permiteTransicion(OcrIntentoEstado::COMPLETADO, OcrIntentoEstado::PROCESANDO));
        $this->assertFalse(OcrIntentoEstado::permiteTransicion(OcrIntentoEstado::FALLIDO, OcrIntentoEstado::PROCESANDO));
        $this->assertTrue(OcrIntentoEstado::esResultadoVigente(OcrIntentoEstado::COMPLETADO));
        $this->assertTrue(OcrIntentoEstado::esResultadoVigente(OcrIntentoEstado::REQUIERE_REVISION));
    }

    public function test_recepcion_whitelist(): void
    {
        $this->assertTrue(RecepcionEstado::permiteTransicion(RecepcionEstado::BORRADOR, RecepcionEstado::CONFIRMADA));
        $this->assertFalse(RecepcionEstado::permiteTransicion(RecepcionEstado::CONFIRMADA, RecepcionEstado::BORRADOR));
        $this->assertTrue(RecepcionEstado::esTerminal(RecepcionEstado::CONFIRMADA));
    }

    public function test_novedad_whitelist(): void
    {
        $this->assertTrue(NovedadEstado::permiteTransicion(NovedadEstado::ABIERTA, NovedadEstado::RESUELTA));
        $this->assertTrue(NovedadEstado::permiteTransicion(NovedadEstado::ABIERTA, NovedadEstado::CERRADA));
        $this->assertTrue(NovedadEstado::permiteTransicion(NovedadEstado::RESUELTA, NovedadEstado::CERRADA));
        $this->assertFalse(NovedadEstado::permiteTransicion(NovedadEstado::CERRADA, NovedadEstado::ABIERTA));
        $this->assertTrue(NovedadEstado::esTerminal(NovedadEstado::CERRADA));
    }
}
