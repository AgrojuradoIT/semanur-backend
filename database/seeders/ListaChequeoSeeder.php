<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\ListaChequeo;
use App\Models\ItemListaChequeo;
use App\Models\PreoperacionalTemplate;
use App\Models\PreoperacionalTemplateSection;
use App\Models\PreoperacionalTemplateItem;

class ListaChequeoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // ============================================================
        // LEGACY: Old checklist tables (for backward compatibility)
        // ============================================================
        $this->seedLegacyChecklists();

        // ============================================================
        // V2: New preoperacional templates (official Excel forms)
        // ============================================================
        $this->seedV2Templates();
    }

    private function seedLegacyChecklists(): void
    {
        // 1. Plantilla para Tractores Agrícolas (Tractor)
        $ractorAgricola = ListaChequeo::updateOrCreate(
            ['tipo_vehiculo' => 'tractor'],
            [
                'nombre' => 'Inspección Preoperacional de Tractor Agrícola',
                'descripcion' => 'Revisión estándar de niveles, hidráulico y mecánica para Tractores Agrícolas.',
                'activo' => true
            ]
        );

        $itemsTractor = [
            ['pregunta' => 'Aceite de Motor', 'es_critico' => true, 'orden' => 1],
            ['pregunta' => 'Refrigerante / Agua', 'es_critico' => true, 'orden' => 2],
            ['pregunta' => 'Aceite Hidráulico', 'es_critico' => true, 'orden' => 3],
            ['pregunta' => 'Combustible (Drenaje de agua)', 'es_critico' => false, 'orden' => 4],
            ['pregunta' => 'Batería y Bornes', 'es_critico' => false, 'orden' => 5],
            ['pregunta' => 'Luces de Trabajo', 'es_critico' => false, 'orden' => 6],
            ['pregunta' => 'Tablero de Instrumentos', 'es_critico' => false, 'orden' => 7],
            ['pregunta' => 'Presión de Llantas', 'es_critico' => false, 'orden' => 8],
            ['pregunta' => 'Puntos de Engrase', 'es_critico' => false, 'orden' => 9],
            ['pregunta' => 'Frenos', 'es_critico' => true, 'orden' => 10],
        ];

        ItemListaChequeo::where('lista_chequeo_id', $ractorAgricola->id)->delete();
        foreach ($itemsTractor as $item) {
            ItemListaChequeo::create([
                'lista_chequeo_id' => $ractorAgricola->id,
                'pregunta' => $item['pregunta'],
                'es_critico' => $item['es_critico'],
                'orden' => $item['orden'],
                'tipo_respuesta' => 'cumple_falla',
            ]);
        }

        // 2. Plantilla para Tractor Aéreo
        $ractorAereo = ListaChequeo::updateOrCreate(
            ['tipo_vehiculo' => 'tractor aereo'],
            [
                'nombre' => 'Inspección Preoperacional de Tractor Aéreo',
                'descripcion' => 'Mantenimiento crítico enfocado en motor.',
                'activo' => true
            ]
        );

        $itemsAereo = [
            ['pregunta' => 'Nivel de Aceite de Motor', 'es_critico' => true, 'orden' => 1],
            ['pregunta' => 'Estado del Filtro de Aire', 'es_critico' => true, 'orden' => 2],
            ['pregunta' => 'Fugas de Aceite', 'es_critico' => true, 'orden' => 3],
        ];

        ItemListaChequeo::where('lista_chequeo_id', $ractorAereo->id)->delete();
        foreach ($itemsAereo as $item) {
            ItemListaChequeo::create([
                'lista_chequeo_id' => $ractorAereo->id,
                'pregunta' => $item['pregunta'],
                'es_critico' => $item['es_critico'],
                'orden' => $item['orden'],
                'tipo_respuesta' => 'cumple_falla',
            ]);
        }

        // 3. Plantilla Genérica para Volquetas, Camionetas y Vehículos Pesados
        $tiposPesados = ['volqueta', 'camioneta', 'moto', 'maquinaria', 'camion', 'trailer', 'minicargador', 'retroexcavadora'];

        foreach ($tiposPesados as $tipo) {
            $listaPesado = ListaChequeo::updateOrCreate(
                ['tipo_vehiculo' => $tipo],
                [
                    'nombre' => 'Inspección Preoperacional de ' . $tipo,
                    'descripcion' => 'Revisión de seguridad y niveles para vehículos comerciales de transporte/construcción.',
                    'activo' => true
                ]
            );

            ItemListaChequeo::where('lista_chequeo_id', $listaPesado->id)->delete();
            $itemsPesado = [
                ['pregunta' => 'Aceite de Motor', 'es_critico' => true, 'orden' => 1],
                ['pregunta' => 'Refrigerante', 'es_critico' => true, 'orden' => 2],
                ['pregunta' => 'Líquido de Frenos', 'es_critico' => true, 'orden' => 3],
                ['pregunta' => 'Dirección Hidráulica', 'es_critico' => true, 'orden' => 4],
                ['pregunta' => 'Presión y Estado Llantas Principales', 'es_critico' => true, 'orden' => 5],
                ['pregunta' => 'Pernos Completos', 'es_critico' => true, 'orden' => 6],
                ['pregunta' => 'Llanta de Repuesto', 'es_critico' => false, 'orden' => 7],
                ['pregunta' => 'Luces Altas y Bajas', 'es_critico' => false, 'orden' => 8],
                ['pregunta' => 'Stop y Direccionales', 'es_critico' => false, 'orden' => 9],
                ['pregunta' => 'Pito / Bocina', 'es_critico' => true, 'orden' => 10],
                ['pregunta' => 'Extintor Vigente', 'es_critico' => true, 'orden' => 11],
                ['pregunta' => 'Botiquín de Primeros Auxilios', 'es_critico' => false, 'orden' => 12],
                ['pregunta' => 'Cinturones de Seguridad Funcionales', 'es_critico' => true, 'orden' => 13],
            ];

            foreach ($itemsPesado as $item) {
                ItemListaChequeo::create([
                    'lista_chequeo_id' => $listaPesado->id,
                    'pregunta' => $item['pregunta'],
                    'es_critico' => $item['es_critico'],
                    'orden' => $item['orden'],
                    'tipo_respuesta' => 'cumple_falla',
                ]);
            }
        }

        // 4. Plantilla Universal (fallback para tipos no reconocidos)
        $generica = ListaChequeo::updateOrCreate(
            ['tipo_vehiculo' => 'generico'],
            [
                'nombre' => 'Inspección Preoperacional General',
                'descripcion' => 'Checklist básico para cualquier vehículo o maquinaria.',
                'activo' => true
            ]
        );

        ItemListaChequeo::where('lista_chequeo_id', $generica->id)->delete();
        $itemsGenericos = [
            ['pregunta' => 'Aceite de Motor', 'es_critico' => true, 'orden' => 1],
            ['pregunta' => 'Refrigerante', 'es_critico' => true, 'orden' => 2],
            ['pregunta' => 'Líquido de Frenos', 'es_critico' => true, 'orden' => 3],
            ['pregunta' => 'Luces (altas, bajas, stop, direccionales)', 'es_critico' => true, 'orden' => 4],
            ['pregunta' => 'Llantas (presión y estado)', 'es_critico' => true, 'orden' => 5],
            ['pregunta' => 'Espejos Retrovisores', 'es_critico' => false, 'orden' => 6],
            ['pregunta' => 'Pito / Bocina', 'es_critico' => true, 'orden' => 7],
            ['pregunta' => 'Extintor Vigente', 'es_critico' => true, 'orden' => 8],
            ['pregunta' => 'Cinturón de Seguridad', 'es_critico' => true, 'orden' => 9],
            ['pregunta' => 'Documentos al Día (SOAT, tecnomecánica)', 'es_critico' => true, 'orden' => 10],
        ];

        foreach ($itemsGenericos as $item) {
            ItemListaChequeo::create([
                'lista_chequeo_id' => $generica->id,
                'pregunta' => $item['pregunta'],
                'es_critico' => $item['es_critico'],
                'orden' => $item['orden'],
                'tipo_respuesta' => 'cumple_falla',
            ]);
        }
    }

    private function seedV2Templates(): void
    {
        DB::transaction(function () {
            // ── SMN-FT-33: Tractores Terrestres ──
            $this->seedTractorTerrestre();

            // ── SMN-FT-34: Volquetas ──
            $this->seedVolqueta();

            // ── SMN-FT-35: Excavadora ──
            $this->seedExcavadora();

            // ── SMN-FT-64: Tractor Aéreo ──
            $this->seedTractorAereo();

            // ── Generic fallback for additional vehicle types ──
            $this->seedGenericTemplates();
        });
    }

    private function seedTractorTerrestre(): void
    {
        $template = PreoperacionalTemplate::updateOrCreate(
            ['codigo' => 'SMN-FT-33'],
            [
                'nombre' => 'Inspección Preoperacional - Tractor Terrestre',
                'tipo_vehiculo' => 'tractor',
                'descripcion' => 'Formulario oficial SMN-FT-33 para tractores terrestres.',
                'escala_predeterminada' => 'B_M',
                'requiere_conductor' => false,
                'requiere_documentos_vehiculo' => false,
                'requiere_aprobacion' => false,
                'activo' => true,
                'version' => 1,
            ]
        );

        // Delete existing items and sections to allow re-seeding
        PreoperacionalTemplateItem::where('template_id', $template->id)->delete();
        PreoperacionalTemplateSection::where('template_id', $template->id)->delete();

        $sections = [
            [
                'nombre' => 'PARTE EXTERNA',
                'orden' => 1,
                'items' => [
                    ['pregunta' => 'Espejos laterales', 'es_critico' => false, 'orden' => 1],
                    ['pregunta' => 'Luces traseras', 'es_critico' => false, 'orden' => 2],
                    ['pregunta' => 'Alarma de Reversa', 'es_critico' => true, 'orden' => 3],
                    ['pregunta' => 'Extintor', 'es_critico' => true, 'orden' => 4],
                    ['pregunta' => 'Llantas', 'es_critico' => true, 'orden' => 5],
                    ['pregunta' => 'Luces direccionales', 'es_critico' => false, 'orden' => 6],
                    ['pregunta' => 'Luces delanteras', 'es_critico' => false, 'orden' => 7],
                    ['pregunta' => 'Luces de Parqueo', 'es_critico' => false, 'orden' => 8],
                    ['pregunta' => 'Tanque de combustible', 'es_critico' => true, 'orden' => 9],
                ],
            ],
            [
                'nombre' => 'COMPORTAMIENTO DEL MOTOR',
                'orden' => 2,
                'items' => [
                    ['pregunta' => 'Fuente de energía', 'es_critico' => true, 'orden' => 1],
                    ['pregunta' => 'Conexiones eléctricas', 'es_critico' => true, 'orden' => 2],
                    ['pregunta' => 'Nivel de agua de refrigeración', 'es_critico' => true, 'orden' => 3],
                    ['pregunta' => 'Mangueras', 'es_critico' => false, 'orden' => 4],
                    ['pregunta' => 'Líneas hidráulicas', 'es_critico' => true, 'orden' => 5],
                    ['pregunta' => 'Niveles de aceite motor/hidráulico', 'es_critico' => true, 'orden' => 6],
                    ['pregunta' => 'Cableado eléctrico', 'es_critico' => true, 'orden' => 7],
                    ['pregunta' => 'Botella de dirección/nivel de aceite', 'es_critico' => true, 'orden' => 8],
                ],
            ],
            [
                'nombre' => 'INTERIOR DE LA CABINA',
                'orden' => 3,
                'items' => [
                    ['pregunta' => 'Cinturón de seguridad', 'es_critico' => true, 'orden' => 1],
                    ['pregunta' => 'Pito', 'es_critico' => false, 'orden' => 2],
                    ['pregunta' => 'Timón o volante', 'es_critico' => true, 'orden' => 3],
                    ['pregunta' => 'Frenos', 'es_critico' => true, 'orden' => 4],
                    ['pregunta' => 'Botiquín', 'es_critico' => false, 'orden' => 5],
                    ['pregunta' => 'Horómetro', 'es_critico' => false, 'orden' => 6],
                ],
            ],
        ];

        $this->insertSectionsAndItems($template, $sections);
    }

    private function seedVolqueta(): void
    {
        $template = PreoperacionalTemplate::updateOrCreate(
            ['codigo' => 'SMN-FT-34'],
            [
                'nombre' => 'Inspección Preoperacional - Volqueta',
                'tipo_vehiculo' => 'volqueta',
                'descripcion' => 'Formulario oficial SMN-FT-34 para volquetas.',
                'escala_predeterminada' => 'B_M',
                'requiere_conductor' => true,
                'requiere_documentos_vehiculo' => true,
                'requiere_aprobacion' => false,
                'activo' => true,
                'version' => 1,
            ]
        );

        // Delete existing items (no sections for volqueta)
        PreoperacionalTemplateItem::where('template_id', $template->id)->delete();
        PreoperacionalTemplateSection::where('template_id', $template->id)->delete();

        $items = [
            ['pregunta' => 'Carrocería', 'es_critico' => false, 'orden' => 1],
            ['pregunta' => 'Vidrios Parabrisas Laterales Traseros y Ventanillas', 'es_critico' => false, 'orden' => 2],
            ['pregunta' => 'Puertas de Salida', 'es_critico' => false, 'orden' => 3],
            ['pregunta' => 'Espejos Retrovisores', 'es_critico' => false, 'orden' => 4],
            ['pregunta' => 'Luces Principales de Freno Reversa Traseras Parqueo Dirección', 'es_critico' => true, 'orden' => 5],
            ['pregunta' => 'Llantas', 'es_critico' => true, 'orden' => 6],
            ['pregunta' => 'Llanta de Repuesto', 'es_critico' => false, 'orden' => 7],
            ['pregunta' => 'Escalerillas de acceso', 'es_critico' => false, 'orden' => 8],
            ['pregunta' => 'Tanque de Combustible', 'es_critico' => true, 'orden' => 9],
            ['pregunta' => 'Dirección/suspensión (terminales)', 'es_critico' => true, 'orden' => 10],
            ['pregunta' => 'Indicadores (hidráulico - refrigerante)', 'es_critico' => false, 'orden' => 11],
            ['pregunta' => 'Motor- refrigerante-horometro', 'es_critico' => true, 'orden' => 12],
            ['pregunta' => 'Control fugas de aire', 'es_critico' => true, 'orden' => 13],
            ['pregunta' => 'Gastos de levante de volqueta', 'es_critico' => true, 'orden' => 14],
            ['pregunta' => 'Compuerta volqueta', 'es_critico' => true, 'orden' => 15],
            ['pregunta' => 'Sistema eléctrico aislado', 'es_critico' => true, 'orden' => 16],
            ['pregunta' => 'Asiento', 'es_critico' => false, 'orden' => 17],
            ['pregunta' => 'Luces de Tablero', 'es_critico' => false, 'orden' => 18],
            ['pregunta' => 'Luces Interiores', 'es_critico' => false, 'orden' => 19],
            ['pregunta' => 'Freno de servicios', 'es_critico' => true, 'orden' => 20],
            ['pregunta' => 'Freno de emergencias', 'es_critico' => true, 'orden' => 21],
            ['pregunta' => 'Cinturón de seguridad', 'es_critico' => true, 'orden' => 22],
            ['pregunta' => 'Alarma de reversa', 'es_critico' => true, 'orden' => 23],
            ['pregunta' => 'Indicadores de Luces de Parqueo Dirección altas y bajas', 'es_critico' => false, 'orden' => 24],
            ['pregunta' => 'Indicadores de Nivel de Combustible', 'es_critico' => false, 'orden' => 25],
            ['pregunta' => 'Indicadores de Temperatura', 'es_critico' => false, 'orden' => 26],
            ['pregunta' => 'Indicador de Velocidad', 'es_critico' => false, 'orden' => 27],
            ['pregunta' => 'Tacómetro', 'es_critico' => false, 'orden' => 28],
            ['pregunta' => 'Plumillas Limpia Vidrios', 'es_critico' => false, 'orden' => 29],
            ['pregunta' => 'Pito', 'es_critico' => false, 'orden' => 30],
            ['pregunta' => 'Extintor de Incendios', 'es_critico' => true, 'orden' => 31],
            ['pregunta' => 'Botiquín', 'es_critico' => false, 'orden' => 32],
            ['pregunta' => 'Señales dos conos o triángulos reflectivo', 'es_critico' => false, 'orden' => 33],
            ['pregunta' => 'Linterna', 'es_critico' => false, 'orden' => 34],
            ['pregunta' => 'Gato para elevar vehículo', 'es_critico' => false, 'orden' => 35],
            ['pregunta' => 'Caja de herramienta básica', 'es_critico' => false, 'orden' => 36],
        ];

        foreach ($items as $item) {
            PreoperacionalTemplateItem::create([
                'template_id' => $template->id,
                'section_id' => null,
                'pregunta' => $item['pregunta'],
                'es_critico' => $item['es_critico'],
                'orden' => $item['orden'],
            ]);
        }
    }

    private function seedExcavadora(): void
    {
        $template = PreoperacionalTemplate::updateOrCreate(
            ['codigo' => 'SMN-FT-35'],
            [
                'nombre' => 'Inspección Preoperacional - Excavadora',
                'tipo_vehiculo' => 'excavadora',
                'descripcion' => 'Formulario oficial SMN-FT-35 para excavadoras.',
                'escala_predeterminada' => 'B_M',
                'requiere_conductor' => false,
                'requiere_documentos_vehiculo' => false,
                'requiere_aprobacion' => false,
                'activo' => true,
                'version' => 1,
            ]
        );

        PreoperacionalTemplateItem::where('template_id', $template->id)->delete();
        PreoperacionalTemplateSection::where('template_id', $template->id)->delete();

        $sections = [
            [
                'nombre' => 'NIVELES',
                'orden' => 1,
                'items' => [
                    ['pregunta' => 'Aceite Motor', 'es_critico' => true, 'orden' => 1],
                    ['pregunta' => 'Aceite Hidráulico', 'es_critico' => true, 'orden' => 2],
                    ['pregunta' => 'Aceite Transmisión', 'es_critico' => true, 'orden' => 3],
                ],
            ],
            [
                'nombre' => 'TREN RODAJES',
                'orden' => 2,
                'items' => [
                    ['pregunta' => 'Zapatas', 'es_critico' => true, 'orden' => 1],
                    ['pregunta' => 'Eslabones', 'es_critico' => false, 'orden' => 2],
                    ['pregunta' => 'Bujes', 'es_critico' => false, 'orden' => 3],
                    ['pregunta' => 'Guarda guías', 'es_critico' => false, 'orden' => 4],
                    ['pregunta' => 'Rueda Guía Y Catarina', 'es_critico' => true, 'orden' => 5],
                    ['pregunta' => 'Rodillos (Superiores Y Inferiores)', 'es_critico' => false, 'orden' => 6],
                ],
            ],
            [
                'nombre' => 'FUNCIONAMIENTO BÁSICO',
                'orden' => 3,
                'items' => [
                    ['pregunta' => 'Encendido Motor', 'es_critico' => true, 'orden' => 1],
                    ['pregunta' => 'Sistema De Avance Y Velocidad', 'es_critico' => true, 'orden' => 2],
                    ['pregunta' => 'Sistema De Paro', 'es_critico' => true, 'orden' => 3],
                ],
            ],
            [
                'nombre' => 'SISTEMA HIDRÁULICO',
                'orden' => 4,
                'items' => [
                    ['pregunta' => 'Acción Pluma Excavadora', 'es_critico' => true, 'orden' => 1],
                    ['pregunta' => 'Acción Brazo Excavadora', 'es_critico' => true, 'orden' => 2],
                    ['pregunta' => 'Acción Cucharon', 'es_critico' => true, 'orden' => 3],
                    ['pregunta' => 'Control De Rotación', 'es_critico' => true, 'orden' => 4],
                ],
            ],
            [
                'nombre' => 'ESTACIÓN DEL OPERADOR',
                'orden' => 5,
                'items' => [
                    ['pregunta' => 'Horometro', 'es_critico' => false, 'orden' => 1],
                    ['pregunta' => 'Indicadores (combustible, temperatura, amperajes)', 'es_critico' => false, 'orden' => 2],
                    ['pregunta' => 'Sistema De Aire Acondicionado', 'es_critico' => false, 'orden' => 3],
                    ['pregunta' => 'Espejo Retrovisor', 'es_critico' => false, 'orden' => 4],
                    ['pregunta' => 'Silla Operador', 'es_critico' => false, 'orden' => 5],
                    ['pregunta' => 'Corneta Claxon', 'es_critico' => false, 'orden' => 6],
                    ['pregunta' => 'Cinturón de seguridad', 'es_critico' => true, 'orden' => 7],
                    ['pregunta' => 'Panorámico Y Demás Vidrios', 'es_critico' => false, 'orden' => 8],
                    ['pregunta' => 'Cabina En General', 'es_critico' => false, 'orden' => 9],
                    ['pregunta' => 'Limpia Brisas', 'es_critico' => false, 'orden' => 10],
                    ['pregunta' => 'Pasamanos', 'es_critico' => false, 'orden' => 11],
                    ['pregunta' => 'Escalones', 'es_critico' => false, 'orden' => 12],
                ],
            ],
            [
                'nombre' => 'HERRAMIENTAS DE CORTE CUCHARÓN',
                'orden' => 6,
                'items' => [
                    ['pregunta' => 'Porta Dientes Cucharon Excavadora', 'es_critico' => true, 'orden' => 1],
                    ['pregunta' => 'Dientes Cucharon', 'es_critico' => true, 'orden' => 2],
                ],
            ],
            [
                'nombre' => 'ACCESORIOS',
                'orden' => 7,
                'items' => [
                    ['pregunta' => 'Interruptores', 'es_critico' => false, 'orden' => 1],
                    ['pregunta' => 'Alarma De Retroceso', 'es_critico' => true, 'orden' => 2],
                    ['pregunta' => 'Luces De Operación Laterales', 'es_critico' => false, 'orden' => 3],
                    ['pregunta' => 'Luces De Operación Traseras', 'es_critico' => false, 'orden' => 4],
                    ['pregunta' => 'Batería', 'es_critico' => true, 'orden' => 5],
                    ['pregunta' => 'Tapas De Tanques (Combustible, Hidráulico, Refrigerante)', 'es_critico' => true, 'orden' => 6],
                    ['pregunta' => 'Botiquín', 'es_critico' => false, 'orden' => 7],
                    ['pregunta' => 'Extintor', 'es_critico' => true, 'orden' => 8],
                    ['pregunta' => 'Llave De Encendido', 'es_critico' => false, 'orden' => 9],
                    ['pregunta' => 'Llave De Paso', 'es_critico' => false, 'orden' => 10],
                ],
            ],
            [
                'nombre' => 'ESTADO VISUAL DEL EQUIPO',
                'orden' => 8,
                'items' => [
                    ['pregunta' => 'Pintura', 'es_critico' => false, 'orden' => 1],
                    ['pregunta' => 'Mangueras (Hidráulicas/Aguas)', 'es_critico' => true, 'orden' => 2],
                    ['pregunta' => 'Fugas', 'es_critico' => true, 'orden' => 3],
                    ['pregunta' => 'Aseo General', 'es_critico' => false, 'orden' => 4],
                ],
            ],
        ];

        $this->insertSectionsAndItems($template, $sections);
    }

    private function seedTractorAereo(): void
    {
        $template = PreoperacionalTemplate::updateOrCreate(
            ['codigo' => 'SMN-FT-64'],
            [
                'nombre' => 'Inspección Preoperacional - Tractor Aéreo',
                'tipo_vehiculo' => 'tractor aereo',
                'descripcion' => 'Formulario oficial SMN-FT-64 para tractores aéreos.',
                'escala_predeterminada' => 'B_C_NC_M_N_A',
                'requiere_conductor' => false,
                'requiere_documentos_vehiculo' => false,
                'requiere_aprobacion' => true,
                'activo' => true,
                'version' => 1,
            ]
        );

        PreoperacionalTemplateItem::where('template_id', $template->id)->delete();
        PreoperacionalTemplateSection::where('template_id', $template->id)->delete();

        $sections = [
            [
                'nombre' => 'ESTADO MECANICO',
                'orden' => 1,
                'items' => [
                    ['pregunta' => 'Nivel de aceite del motor', 'es_critico' => true, 'orden' => 1],
                    ['pregunta' => 'Nivel de aceite hidráulico', 'es_critico' => true, 'orden' => 2],
                    ['pregunta' => 'Nivel de Diésel (ACPM)', 'es_critico' => true, 'orden' => 3],
                    ['pregunta' => 'Suiche de encendido', 'es_critico' => false, 'orden' => 4],
                    ['pregunta' => 'Mangueras Hidráulicas', 'es_critico' => true, 'orden' => 5],
                    ['pregunta' => 'Cadena de plato de transmisión', 'es_critico' => true, 'orden' => 6],
                    ['pregunta' => 'Piñones de plato de transmisión', 'es_critico' => true, 'orden' => 7],
                    ['pregunta' => 'Balineras de plato de transmisión', 'es_critico' => true, 'orden' => 8],
                    ['pregunta' => 'Filtro Hidráulico', 'es_critico' => true, 'orden' => 9],
                    ['pregunta' => 'Filtro de Aire', 'es_critico' => true, 'orden' => 10],
                    ['pregunta' => 'Filtro de Aceite', 'es_critico' => true, 'orden' => 11],
                    ['pregunta' => 'Filtro de ACPM', 'es_critico' => true, 'orden' => 12],
                    ['pregunta' => 'Estado de las ruedas', 'es_critico' => true, 'orden' => 13],
                    ['pregunta' => 'Palanca de direccionamiento', 'es_critico' => true, 'orden' => 14],
                    ['pregunta' => 'Palanca de aceleración', 'es_critico' => false, 'orden' => 15],
                    ['pregunta' => 'Llave de la bomba este completamente vertical', 'es_critico' => true, 'orden' => 16],
                    ['pregunta' => 'Posición del brazo de avance de la bomba este en posición vertical', 'es_critico' => true, 'orden' => 17],
                    ['pregunta' => 'Gancho anti caídas del plato de transmisión este en posición vertical', 'es_critico' => true, 'orden' => 18],
                    ['pregunta' => 'Resorte de anti caídas', 'es_critico' => true, 'orden' => 19],
                    ['pregunta' => 'Estado de la silla', 'es_critico' => false, 'orden' => 20],
                    ['pregunta' => 'Estado de garrucha de Silla', 'es_critico' => false, 'orden' => 21],
                    ['pregunta' => 'Estado de Caseta de silla', 'es_critico' => false, 'orden' => 22],
                ],
            ],
        ];

        $this->insertSectionsAndItems($template, $sections);
    }

    private function seedGenericTemplates(): void
    {
        $genericTypes = ['camioneta', 'camion', 'retroexcavadora', 'minicargador'];

        foreach ($genericTypes as $tipo) {
            $template = PreoperacionalTemplate::updateOrCreate(
                ['codigo' => 'GEN-' . strtoupper($tipo)],
                [
                    'nombre' => 'Inspección Preoperacional - ' . ucfirst($tipo),
                    'tipo_vehiculo' => $tipo,
                    'descripcion' => 'Plantilla genérica para ' . $tipo . '.',
                    'escala_predeterminada' => 'B_M',
                    'requiere_conductor' => false,
                    'requiere_documentos_vehiculo' => false,
                    'requiere_aprobacion' => false,
                    'activo' => true,
                    'version' => 1,
                ]
            );

            PreoperacionalTemplateItem::where('template_id', $template->id)->delete();
            PreoperacionalTemplateSection::where('template_id', $template->id)->delete();

            $items = [
                ['pregunta' => 'Aceite de Motor', 'es_critico' => true, 'orden' => 1],
                ['pregunta' => 'Refrigerante', 'es_critico' => true, 'orden' => 2],
                ['pregunta' => 'Líquido de Frenos', 'es_critico' => true, 'orden' => 3],
                ['pregunta' => 'Luces (altas, bajas, stop, direccionales)', 'es_critico' => true, 'orden' => 4],
                ['pregunta' => 'Llantas (presión y estado)', 'es_critico' => true, 'orden' => 5],
                ['pregunta' => 'Espejos Retrovisores', 'es_critico' => false, 'orden' => 6],
                ['pregunta' => 'Pito / Bocina', 'es_critico' => true, 'orden' => 7],
                ['pregunta' => 'Extintor Vigente', 'es_critico' => true, 'orden' => 8],
                ['pregunta' => 'Cinturón de Seguridad', 'es_critico' => true, 'orden' => 9],
                ['pregunta' => 'Documentos al Día (SOAT, tecnomecánica)', 'es_critico' => true, 'orden' => 10],
            ];

            foreach ($items as $item) {
                PreoperacionalTemplateItem::create([
                    'template_id' => $template->id,
                    'section_id' => null,
                    'pregunta' => $item['pregunta'],
                    'es_critico' => $item['es_critico'],
                    'orden' => $item['orden'],
                ]);
            }
        }

        // Generic fallback template
        $template = PreoperacionalTemplate::updateOrCreate(
            ['codigo' => 'GEN-GENERICO'],
            [
                'nombre' => 'Inspección Preoperacional General',
                'tipo_vehiculo' => 'generico',
                'descripcion' => 'Plantilla genérica de fallback para cualquier vehículo o maquinaria.',
                'escala_predeterminada' => 'B_M',
                'requiere_conductor' => false,
                'requiere_documentos_vehiculo' => false,
                'requiere_aprobacion' => false,
                'activo' => true,
                'version' => 1,
            ]
        );

        PreoperacionalTemplateItem::where('template_id', $template->id)->delete();
        PreoperacionalTemplateSection::where('template_id', $template->id)->delete();

        $items = [
            ['pregunta' => 'Aceite de Motor', 'es_critico' => true, 'orden' => 1],
            ['pregunta' => 'Refrigerante', 'es_critico' => true, 'orden' => 2],
            ['pregunta' => 'Líquido de Frenos', 'es_critico' => true, 'orden' => 3],
            ['pregunta' => 'Luces (altas, bajas, stop, direccionales)', 'es_critico' => true, 'orden' => 4],
            ['pregunta' => 'Llantas (presión y estado)', 'es_critico' => true, 'orden' => 5],
            ['pregunta' => 'Espejos Retrovisores', 'es_critico' => false, 'orden' => 6],
            ['pregunta' => 'Pito / Bocina', 'es_critico' => true, 'orden' => 7],
            ['pregunta' => 'Extintor Vigente', 'es_critico' => true, 'orden' => 8],
            ['pregunta' => 'Cinturón de Seguridad', 'es_critico' => true, 'orden' => 9],
            ['pregunta' => 'Documentos al Día (SOAT, tecnomecánica)', 'es_critico' => true, 'orden' => 10],
        ];

        foreach ($items as $item) {
            PreoperacionalTemplateItem::create([
                'template_id' => $template->id,
                'section_id' => null,
                'pregunta' => $item['pregunta'],
                'es_critico' => $item['es_critico'],
                'orden' => $item['orden'],
            ]);
        }
    }

    /**
     * Helper: insert sections and their items for a template.
     */
    private function insertSectionsAndItems(PreoperacionalTemplate $template, array $sections): void
    {
        foreach ($sections as $sectionData) {
            $section = PreoperacionalTemplateSection::create([
                'template_id' => $template->id,
                'nombre' => $sectionData['nombre'],
                'orden' => $sectionData['orden'],
            ]);

            foreach ($sectionData['items'] as $itemData) {
                PreoperacionalTemplateItem::create([
                    'template_id' => $template->id,
                    'section_id' => $section->id,
                    'pregunta' => $itemData['pregunta'],
                    'es_critico' => $itemData['es_critico'],
                    'orden' => $itemData['orden'],
                ]);
            }
        }
    }
}
