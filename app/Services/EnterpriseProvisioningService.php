<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;

/**
 * Construye la jerarquía Aplicación → Módulo → Submódulo para los 3
 * perfiles de negocio del sistema (Agrícola, RH, Comercio). Extraído de
 * database/seeders/Concerns/BuildsEnterpriseStructure.php para poder
 * invocarse también desde un controller HTTP (el trait original depende de
 * $this->command, solo disponible en un Seeder ejecutado por Artisan).
 *
 * BuildsEnterpriseStructure delega en esta clase — el árbol vive en un solo
 * lugar, el trait solo le agrega logging de consola encima.
 */
class EnterpriseProvisioningService
{
    /**
     * Aprovisiona la suite correspondiente según la empresa raíz de la que
     * $enterprise es espejo (Enterprise::mirrorSource()).
     *
     * @return array{application: string, created: array{applications: int, modules: int, submodules: int}}
     */
    public function provision(Enterprise $enterprise): array
    {
        $root = $enterprise->mirrorSource;

        if (! $root) {
            throw new \InvalidArgumentException(
                'La empresa no tiene una suite asignada (mirror_source_id vacío).'
            );
        }

        return match ($root->slug) {
            'splendidfarms' => $this->provisionAgricultural($enterprise),
            'grupoesplendido' => $this->provisionCorporateRh($enterprise),
            'splendidbyporvenir' => $this->provisionTrade($enterprise),
            default => throw new \InvalidArgumentException("Suite raíz desconocida: {$root->slug}"),
        };
    }

    /**
     * Perfil "Corporativo": una sola aplicación de Recursos Humanos.
     * Cuerpo idéntico a BuildsEnterpriseStructure::buildCorporateRhSuite()
     * (líneas 27-114 del trait), sin las llamadas a $this->command->info().
     */
    public function provisionCorporateRh(Enterprise $enterprise): array
    {
        [$appsBefore, $modsBefore, $subsBefore] = $this->countTree($enterprise);

        // ========================================
        // APLICACIÓN: RECURSOS HUMANOS
        // ========================================
        $rh = Application::firstOrCreate(
            ['slug' => 'rh', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Recursos Humanos',
                'description' => 'Gestión de personal y asistencia',
                'icon' => 'Users',
                'path' => "/{$enterprise->slug}/rh",
                'is_active' => true,
            ]
        );

        // Módulo: Catálogos
        $rhCatalogos = Module::firstOrCreate(
            ['slug' => 'catalogos', 'application_id' => $rh->id],
            ['name' => 'Catálogos', 'icon' => 'BookOpen', 'order' => 1, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'departamentos', 'module_id' => $rhCatalogos->id],
            ['name' => 'Departamentos', 'icon' => 'Building2', 'order' => 1, 'is_active' => true]
        );
        Submodule::firstOrCreate(
            ['slug' => 'puestos', 'module_id' => $rhCatalogos->id],
            ['name' => 'Puestos', 'icon' => 'Briefcase', 'order' => 2, 'is_active' => true]
        );
        Submodule::firstOrCreate(
            ['slug' => 'horarios', 'module_id' => $rhCatalogos->id],
            ['name' => 'Horarios', 'icon' => 'Calendar', 'order' => 3, 'is_active' => true]
        );

        // Módulo: Empleados
        $rhEmpleados = Module::firstOrCreate(
            ['slug' => 'empleados', 'application_id' => $rh->id],
            ['name' => 'Empleados', 'icon' => 'UserCircle', 'order' => 2, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'lista', 'module_id' => $rhEmpleados->id],
            ['name' => 'Lista de Empleados', 'icon' => 'Users', 'order' => 1, 'is_active' => true]
        );

        // Módulo: Asistencia
        $rhAsistencia = Module::firstOrCreate(
            ['slug' => 'asistencia', 'application_id' => $rh->id],
            ['name' => 'Asistencia', 'icon' => 'Clock', 'order' => 3, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'registros', 'module_id' => $rhAsistencia->id],
            ['name' => 'Registros', 'icon' => 'ClipboardList', 'order' => 1, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'checador', 'module_id' => $rhAsistencia->id],
            ['name' => 'Checador', 'icon' => 'ScanLine', 'order' => 2, 'is_active' => true]
        );

        // Módulo: Gestión (Vacaciones e Incidencias)
        $rhGestion = Module::firstOrCreate(
            ['slug' => 'gestion', 'application_id' => $rh->id],
            ['name' => 'Gestión', 'icon' => 'ClipboardCheck', 'order' => 4, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'vacaciones', 'module_id' => $rhGestion->id],
            ['name' => 'Vacaciones', 'icon' => 'Sun', 'order' => 1, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'incidencias', 'module_id' => $rhGestion->id],
            ['name' => 'Incidencias', 'icon' => 'AlertTriangle', 'order' => 2, 'is_active' => true]
        );

        return $this->summarize('Recursos Humanos', $enterprise, $appsBefore, $modsBefore, $subsBefore);
    }

    /**
     * Perfil "Agrícola completo": Administración + Inventario + Contabilidad
     * + Operación Agrícola (Agrícola, Cosecha, Empaque). Cuerpo idéntico a
     * BuildsEnterpriseStructure::buildAgriculturalSuite() (líneas 127-508
     * del trait), sin las llamadas a $this->command->info().
     *
     * ⚠️ Cosecha (SalidaCampoCosechaController) y Empaque
     * (RecepcionEmpaqueController) transmiten sus eventos de tiempo real a
     * un canal con el nombre de empresa hardcodeado a 'splendidfarms' — una
     * empresa distinta que use estos submódulos no recibirá el push en
     * vivo (los datos se guardan/cargan bien por REST normal, solo el
     * WebSocket queda mudo). No es un bug de este servicio; ver tarea aparte.
     */
    public function provisionAgricultural(Enterprise $enterprise): array
    {
        [$appsBefore, $modsBefore, $subsBefore] = $this->countTree($enterprise);

        // ========================================
        // APLICACIÓN: ADMINISTRACIÓN
        // ========================================
        $administration = Application::firstOrCreate(
            ['slug' => 'administration', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Administración',
                'description' => 'Gestión administrativa general',
                'icon' => 'Settings',
                'path' => "/{$enterprise->slug}/administration",
                'is_active' => true,
            ]
        );

        // Módulo: Agrícola
        $agricola = Module::firstOrCreate(
            ['slug' => 'agricola', 'application_id' => $administration->id],
            ['name' => 'Agrícola', 'icon' => 'Sprout', 'order' => 1, 'is_active' => true]
        );

        $agricolaSubmodules = [
            ['slug' => 'cultivos', 'name' => 'Cultivos', 'icon' => 'Sprout', 'order' => 1],
            ['slug' => 'ciclos-agricolas', 'name' => 'Ciclos Agrícolas', 'icon' => 'RefreshCw', 'order' => 2],
            ['slug' => 'temporadas', 'name' => 'Temporadas', 'icon' => 'CalendarDays', 'order' => 3],
            ['slug' => 'variedades-cultivo', 'name' => 'Variedades de Cultivo', 'icon' => 'Leaf', 'order' => 4],
            ['slug' => 'tipos-variedades', 'name' => 'Tipos de Variedad', 'icon' => 'Carrot', 'order' => 5],
            ['slug' => 'productores', 'name' => 'Productores', 'icon' => 'Tractor', 'order' => 6],
            ['slug' => 'zonas-cultivo', 'name' => 'Zonas de Cultivo', 'icon' => 'MapPin', 'order' => 7],
            ['slug' => 'lotes', 'name' => 'Lotes', 'icon' => 'Map', 'order' => 8],
            ['slug' => 'calibres', 'name' => 'Calibres', 'icon' => 'Ruler', 'order' => 9],
        ];

        foreach ($agricolaSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $agricola->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }

        // Módulo: Compras Agrícolas
        $comprasAgricolas = Module::firstOrCreate(
            ['slug' => 'compras-agricolas', 'application_id' => $administration->id],
            ['name' => 'Compras Agrícolas', 'icon' => 'HandCoins', 'order' => 4, 'is_active' => true]
        );

        $comprasAgricolasSubmodules = [
            ['slug' => 'convenios-compra', 'name' => 'Convenios de Compra', 'icon' => 'Handshake', 'order' => 1],
            ['slug' => 'liquidaciones', 'name' => 'Liquidaciones', 'icon' => 'Receipt', 'order' => 2],
            ['slug' => 'tablero-productores', 'name' => 'Tablero de Productores', 'icon' => 'BarChart3', 'order' => 3],
            ['slug' => 'abonos', 'name' => 'Abonos', 'icon' => 'Wallet', 'order' => 4],
        ];

        foreach ($comprasAgricolasSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $comprasAgricolas->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }

        // Módulo: Organización
        $organizacion = Module::firstOrCreate(
            ['slug' => 'organizacion', 'application_id' => $administration->id],
            ['name' => 'Organización', 'icon' => 'Building', 'order' => 2, 'is_active' => true]
        );

        $orgSubmodules = [
            ['slug' => 'sucursales', 'name' => 'Sucursales', 'icon' => 'Building2', 'order' => 1],
            ['slug' => 'tipos-entidades', 'name' => 'Tipos de Entidades', 'icon' => 'FileType', 'order' => 2],
            ['slug' => 'entidades', 'name' => 'Entidades', 'icon' => 'Landmark', 'order' => 3],
            ['slug' => 'areas', 'name' => 'Áreas', 'icon' => 'LayoutGrid', 'order' => 4],
        ];

        foreach ($orgSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $organizacion->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }

        // Módulo: Catálogos
        $catalogos = Module::firstOrCreate(
            ['slug' => 'catalogos', 'application_id' => $administration->id],
            ['name' => 'Catálogos', 'icon' => 'FolderOpen', 'order' => 3, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'proveedores', 'module_id' => $catalogos->id],
            ['name' => 'Proveedores', 'icon' => 'Truck', 'order' => 1, 'is_active' => true]
        );

        // Módulo: Personal (Empleados + Puestos + Grupos + Contratos)
        $personal = Module::firstOrCreate(
            ['slug' => 'personal', 'application_id' => $administration->id],
            ['name' => 'Personal', 'icon' => 'Users', 'order' => 5, 'is_active' => true]
        );

        $personalSubmodules = [
            ['slug' => 'empleados', 'name' => 'Empleados', 'icon' => 'UserSquare', 'order' => 1],
            ['slug' => 'puestos', 'name' => 'Puestos', 'icon' => 'Briefcase', 'order' => 2],
            ['slug' => 'grupos', 'name' => 'Grupos Salariales', 'icon' => 'Grid3X3', 'order' => 3],
            ['slug' => 'contratos', 'name' => 'Contratos', 'icon' => 'FileSignature', 'order' => 4],
            ['slug' => 'asistencia', 'name' => 'Asistencia', 'icon' => 'ClipboardList', 'order' => 5],
            ['slug' => 'nomina', 'name' => 'Nómina', 'icon' => 'Calculator', 'order' => 6],
            ['slug' => 'checador-campo', 'name' => 'Checador de Campo', 'icon' => 'ScanFace', 'order' => 7],
            ['slug' => 'revision-asistencia', 'name' => 'Revisión de Asistencia', 'icon' => 'ShieldCheck', 'order' => 8],
        ];

        foreach ($personalSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $personal->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }

        // Módulo: Reportes
        $reportesAdmin = Module::firstOrCreate(
            ['slug' => 'reportes', 'application_id' => $administration->id],
            ['name' => 'Reportes', 'icon' => 'BarChart3', 'order' => 6, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'embarques', 'module_id' => $reportesAdmin->id],
            ['name' => 'Embarques', 'icon' => 'Truck', 'order' => 1, 'is_active' => true]
        );

        // ========================================
        // APLICACIÓN: INVENTARIO
        // ========================================
        $inventario = Application::firstOrCreate(
            ['slug' => 'inventario', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Inventario',
                'description' => 'Sistema de gestión de inventarios y almacenes',
                'icon' => 'Package',
                'path' => "/{$enterprise->slug}/inventario",
                'is_active' => true,
            ]
        );

        // Módulo: Catálogos de Inventario
        $invCatalogos = Module::firstOrCreate(
            ['slug' => 'catalogos', 'application_id' => $inventario->id],
            ['name' => 'Catálogos', 'icon' => 'FolderOpen', 'order' => 1, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'categorias', 'module_id' => $invCatalogos->id],
            ['name' => 'Categorías', 'icon' => 'Tags', 'order' => 1, 'is_active' => true]
        );
        Submodule::firstOrCreate(
            ['slug' => 'articulos', 'module_id' => $invCatalogos->id],
            ['name' => 'Artículos', 'icon' => 'Package', 'order' => 2, 'is_active' => true]
        );
        Submodule::firstOrCreate(
            ['slug' => 'recetas', 'module_id' => $invCatalogos->id],
            ['name' => 'Recetas', 'icon' => 'ChefHat', 'order' => 3, 'is_active' => true]
        );
        Submodule::firstOrCreate(
            ['slug' => 'tipos-carga', 'module_id' => $invCatalogos->id],
            ['name' => 'Tipos de Carga', 'icon' => 'BoxSelect', 'order' => 4, 'is_active' => true]
        );

        // Módulo: Operaciones
        $operaciones = Module::firstOrCreate(
            ['slug' => 'operaciones', 'application_id' => $inventario->id],
            ['name' => 'Operaciones', 'icon' => 'ArrowLeftRight', 'order' => 2, 'is_active' => true]
        );

        $opSubmodules = [
            ['slug' => 'entradas', 'name' => 'Entradas', 'icon' => 'ArrowDownLeft', 'order' => 1],
            ['slug' => 'salidas', 'name' => 'Salidas', 'icon' => 'ArrowUpRight', 'order' => 2],
            ['slug' => 'transferencias', 'name' => 'Transferencias', 'icon' => 'ArrowLeftRight', 'order' => 3],
            ['slug' => 'ajustes', 'name' => 'Ajustes', 'icon' => 'SlidersHorizontal', 'order' => 4],
        ];

        foreach ($opSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $operaciones->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }

        // Módulo: Reportes
        $reportes = Module::firstOrCreate(
            ['slug' => 'reportes', 'application_id' => $inventario->id],
            ['name' => 'Reportes', 'icon' => 'BarChart3', 'order' => 3, 'is_active' => true]
        );

        $repSubmodules = [
            ['slug' => 'stock', 'name' => 'Existencias', 'icon' => 'Boxes', 'order' => 1],
            ['slug' => 'movimientos', 'name' => 'Movimientos', 'icon' => 'History', 'order' => 2],
            ['slug' => 'valorizado', 'name' => 'Valorizado', 'icon' => 'DollarSign', 'order' => 3],
        ];

        foreach ($repSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $reportes->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }

        // Módulo: Compras
        $compras = Module::firstOrCreate(
            ['slug' => 'compras', 'application_id' => $inventario->id],
            ['name' => 'Compras', 'icon' => 'ShoppingCart', 'order' => 4, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'ordenes-compra', 'module_id' => $compras->id],
            ['name' => 'Órdenes de Compra', 'icon' => 'FileText', 'order' => 1, 'is_active' => true]
        );
        Submodule::firstOrCreate(
            ['slug' => 'recepciones', 'module_id' => $compras->id],
            ['name' => 'Recepciones', 'icon' => 'PackageCheck', 'order' => 2, 'is_active' => true]
        );

        // ========================================
        // APLICACIÓN: CONTABILIDAD
        // ========================================
        $accounting = Application::firstOrCreate(
            ['slug' => 'accounting', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Contabilidad',
                'description' => 'Gestión contable y financiera',
                'icon' => 'Calculator',
                'path' => "/{$enterprise->slug}/accounting",
                'is_active' => true,
            ]
        );

        // Módulo: Cuentas por Pagar
        $cxp = Module::firstOrCreate(
            ['slug' => 'cxp', 'application_id' => $accounting->id],
            ['name' => 'Cuentas por Pagar', 'icon' => 'Receipt', 'order' => 1, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'documentos', 'module_id' => $cxp->id],
            ['name' => 'Documentos', 'icon' => 'FileText', 'order' => 1, 'is_active' => true]
        );

        // ========================================
        // APLICACIÓN: OPERACIÓN AGRÍCOLA
        // ========================================
        $operacionAgricola = Application::firstOrCreate(
            ['slug' => 'operacion-agricola', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Operación Agrícola',
                'description' => 'Gestión de operaciones agrícolas, siembra, visitas y aplicaciones',
                'icon' => 'Tractor',
                'path' => "/{$enterprise->slug}/operacion-agricola",
                'is_active' => true,
            ]
        );

        // Módulo: Agrícola
        $oaAgricola = Module::firstOrCreate(
            ['slug' => 'agricola', 'application_id' => $operacionAgricola->id],
            ['name' => 'Agrícola', 'icon' => 'Sprout', 'order' => 1, 'is_active' => true]
        );

        $oaSubmodules = [
            ['slug' => 'productores', 'name' => 'Productores', 'icon' => 'Users', 'order' => 1],
            ['slug' => 'zonas-cultivo', 'name' => 'Zonas de Cultivo', 'icon' => 'Map', 'order' => 2],
            ['slug' => 'lotes', 'name' => 'Lotes', 'icon' => 'LandPlot', 'order' => 3],
            ['slug' => 'etapas', 'name' => 'Etapas', 'icon' => 'Layers', 'order' => 4],
            ['slug' => 'plan-siembra', 'name' => 'Plan de Siembra', 'icon' => 'Calendar', 'order' => 5],
            ['slug' => 'visitas-campo', 'name' => 'Visitas de Campo', 'icon' => 'ClipboardCheck', 'order' => 6],
            ['slug' => 'aplicaciones', 'name' => 'Aplicaciones', 'icon' => 'Beaker', 'order' => 7],
            ['slug' => 'requisiciones', 'name' => 'Requisiciones', 'icon' => 'ShoppingCart', 'order' => 8],
            ['slug' => 'costeo-agricola', 'name' => 'Costeo Agrícola', 'icon' => 'Calculator', 'order' => 9],
        ];

        foreach ($oaSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $oaAgricola->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }

        // Módulo: Cosecha
        $oaCosecha = Module::firstOrCreate(
            ['slug' => 'cosecha', 'application_id' => $operacionAgricola->id],
            ['name' => 'Cosecha', 'icon' => 'Wheat', 'order' => 2, 'is_active' => true]
        );

        $cosechaSubmodules = [
            ['slug' => 'dashboard', 'name' => 'Dashboard', 'icon' => 'LayoutDashboard', 'order' => 0],
            ['slug' => 'salidas-campo', 'name' => 'Salidas de Campo', 'icon' => 'Truck', 'order' => 1],
            ['slug' => 'cierres-cosecha', 'name' => 'Cierres de Cosecha', 'icon' => 'ClipboardCheck', 'order' => 2],
            ['slug' => 'ventas-cosecha', 'name' => 'Ventas de Cosecha', 'icon' => 'DollarSign', 'order' => 3],
            ['slug' => 'calidad', 'name' => 'Calidad', 'icon' => 'Award', 'order' => 4],
        ];

        foreach ($cosechaSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $oaCosecha->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }

        // Módulo: Empaque
        $oaEmpaque = Module::firstOrCreate(
            ['slug' => 'empaque', 'application_id' => $operacionAgricola->id],
            ['name' => 'Empaque', 'icon' => 'Package', 'order' => 3, 'is_active' => true]
        );

        $empaqueSubmodules = [
            ['slug' => 'dashboard',    'name' => 'Dashboard',        'icon' => 'LayoutDashboard', 'order' => 0],
            ['slug' => 'dashboard-daniella', 'name' => 'Dashboard Daniella', 'icon' => 'LayoutDashboard', 'order' => 1],
            ['slug' => 'recepciones',  'name' => 'Recepciones',      'icon' => 'Download',       'order' => 1],
            ['slug' => 'lavado',       'name' => 'Lavado',           'icon' => 'Droplets',       'order' => 2],
            ['slug' => 'proceso',      'name' => 'Proceso',          'icon' => 'Layers',         'order' => 3],
            ['slug' => 'produccion',   'name' => 'Producción',       'icon' => 'Package',        'order' => 4],
            ['slug' => 'rezaga',       'name' => 'Rezaga',           'icon' => 'Trash2',         'order' => 5],
            ['slug' => 'embarques',    'name' => 'Embarques',        'icon' => 'Truck',          'order' => 6],
            ['slug' => 'salida-rezaga', 'name' => 'Salida de Rezaga',  'icon' => 'ShoppingCart',   'order' => 7],
            ['slug' => 'calidad',      'name' => 'Calidad',          'icon' => 'ClipboardCheck', 'order' => 8],
            ['slug' => 'reportes',     'name' => 'Reportes',         'icon' => 'FileText',       'order' => 9],
            ['slug' => 'ajuste-peso-rezaga', 'name' => 'Ajuste de Peso Rezaga', 'icon' => 'TrendingDown', 'order' => 10],
            ['slug' => 'recorrido-folios', 'name' => 'Recorrido de Folios', 'icon' => 'Route', 'order' => 11],
            ['slug' => 'balance-masas', 'name' => 'Balance de Masas', 'icon' => 'Scale', 'order' => 12],
        ];

        foreach ($empaqueSubmodules as $sub) {
            Submodule::updateOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $oaEmpaque->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }
        $this->ensureSubmodulePermissionTypes($oaEmpaque, 'recorrido-folios', [
            ['slug' => 'view', 'name' => 'Ver', 'description' => 'Permite ver el submodulo'],
            ['slug' => 'create', 'name' => 'Crear', 'description' => 'Permite crear registros en el submodulo'],
            ['slug' => 'edit', 'name' => 'Editar', 'description' => 'Permite editar registros en el submodulo'],
            ['slug' => 'delete', 'name' => 'Eliminar', 'description' => 'Permite eliminar registros en el submodulo'],
        ]);
        $this->ensureSubmodulePermissionTypes($oaEmpaque, 'balance-masas', [
            ['slug' => 'view', 'name' => 'Ver', 'description' => 'Permite ver el submodulo'],
            ['slug' => 'create', 'name' => 'Crear', 'description' => 'Permite crear registros en el submodulo'],
            ['slug' => 'edit', 'name' => 'Editar', 'description' => 'Permite editar registros en el submodulo'],
            ['slug' => 'delete', 'name' => 'Eliminar', 'description' => 'Permite eliminar registros en el submodulo'],
        ]);
        $this->ensureSubmodulePermissionTypes($oaEmpaque, 'dashboard-daniella', [
            ['slug' => 'view', 'name' => 'Ver', 'description' => 'Permite ver el submodulo'],
            ['slug' => 'create', 'name' => 'Crear', 'description' => 'Permite crear registros en el submodulo'],
            ['slug' => 'edit', 'name' => 'Editar', 'description' => 'Permite editar registros en el submodulo'],
            ['slug' => 'delete', 'name' => 'Eliminar', 'description' => 'Permite eliminar registros en el submodulo'],
        ]);
        $this->ensureSubmodulePermissionTypes($oaEmpaque, 'lavado', [
            ['slug' => 'reiniciar_recorrido', 'name' => 'Reiniciar recorrido', 'description' => 'Permite reiniciar el folio y regresarlo a pendiente de lavar'],
            ['slug' => 'ver_historico_lavado', 'name' => 'Ver historico de lavado', 'description' => 'Permite visualizar el apartado historico del submodulo lavado'],
        ]);
        $this->ensureSubmodulePermissionTypes($oaEmpaque, 'salida-rezaga', [
            ['slug' => 'validar_salida_rezaga', 'name' => 'Validar salida de rezaga', 'description' => 'Permite revisar y validar salidas de rezaga con ticket de transferencia'],
            ['slug' => 'ver_ticket_salida_rezaga', 'name' => 'Ver ticket de revisión', 'description' => 'Permite visualizar el ticket de transferencia cargado en la revisión de salida de rezaga'],
            ['slug' => 'ver_observaciones_salida_rezaga', 'name' => 'Ver observaciones de revisión', 'description' => 'Permite visualizar las observaciones capturadas durante la revisión de salida de rezaga'],
        ]);

        return $this->summarize('Suite Agrícola', $enterprise, $appsBefore, $modsBefore, $subsBefore);
    }

    /**
     * Perfil "Comercio/Exportación": Administración + Inventario + Ventas +
     * Exportaciones + Compras de Fruta. Cuerpo idéntico a
     * BuildsEnterpriseStructure::buildTradeSuite() (líneas 514-743 del
     * trait), sin las llamadas a $this->command->info().
     */
    public function provisionTrade(Enterprise $enterprise): array
    {
        [$appsBefore, $modsBefore, $subsBefore] = $this->countTree($enterprise);

        // ========================================
        // APLICACIÓN: ADMINISTRACIÓN
        // ========================================
        $administration = Application::firstOrCreate(
            ['slug' => 'administration', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Administración',
                'description' => 'Gestión de organización y entidades',
                'icon' => 'Settings',
                'path' => "/{$enterprise->slug}/administration",
                'is_active' => true,
            ]
        );

        // Módulo: Organización
        $organizacion = Module::firstOrCreate(
            ['slug' => 'organizacion', 'application_id' => $administration->id],
            ['name' => 'Organización', 'icon' => 'Building', 'order' => 1, 'is_active' => true]
        );

        $orgSubmodules = [
            ['slug' => 'sucursales', 'name' => 'Sucursales', 'icon' => 'Building2', 'order' => 1],
            ['slug' => 'tipos-entidades', 'name' => 'Tipos de Entidades', 'icon' => 'FileType', 'order' => 2],
            ['slug' => 'entidades', 'name' => 'Entidades', 'icon' => 'Landmark', 'order' => 3],
            ['slug' => 'areas', 'name' => 'Áreas', 'icon' => 'LayoutGrid', 'order' => 4],
        ];

        foreach ($orgSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $organizacion->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }

        // ========================================
        // APLICACIÓN: INVENTARIO
        // ========================================
        $inventario = Application::firstOrCreate(
            ['slug' => 'inventario', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Inventario',
                'description' => 'Control de inventarios, compras y almacén',
                'icon' => 'Warehouse',
                'path' => "/{$enterprise->slug}/inventario",
                'is_active' => true,
            ]
        );

        // Módulo: Catálogos
        $invCatalogos = Module::firstOrCreate(
            ['slug' => 'catalogos', 'application_id' => $inventario->id],
            ['name' => 'Catálogos', 'icon' => 'BookOpen', 'order' => 1, 'is_active' => true]
        );

        $catSubmodules = [
            ['slug' => 'categorias', 'name' => 'Categorías', 'icon' => 'Tags', 'order' => 1],
            ['slug' => 'articulos', 'name' => 'Artículos', 'icon' => 'Package', 'order' => 2],
            ['slug' => 'recetas', 'name' => 'Recetas', 'icon' => 'ChefHat', 'order' => 3],
            ['slug' => 'tipos-carga', 'name' => 'Tipos de Carga', 'icon' => 'BoxSelect', 'order' => 4],
        ];
        foreach ($catSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $invCatalogos->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }

        // Módulo: Operaciones
        $operaciones = Module::firstOrCreate(
            ['slug' => 'operaciones', 'application_id' => $inventario->id],
            ['name' => 'Operaciones', 'icon' => 'ArrowLeftRight', 'order' => 2, 'is_active' => true]
        );

        $opSubmodules = [
            ['slug' => 'entradas', 'name' => 'Entradas', 'icon' => 'ArrowDownLeft', 'order' => 1],
            ['slug' => 'salidas', 'name' => 'Salidas', 'icon' => 'ArrowUpRight', 'order' => 2],
            ['slug' => 'transferencias', 'name' => 'Transferencias', 'icon' => 'ArrowLeftRight', 'order' => 3],
            ['slug' => 'ajustes', 'name' => 'Ajustes', 'icon' => 'SlidersHorizontal', 'order' => 4],
        ];
        foreach ($opSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $operaciones->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }

        // Módulo: Reportes
        $reportes = Module::firstOrCreate(
            ['slug' => 'reportes', 'application_id' => $inventario->id],
            ['name' => 'Reportes', 'icon' => 'BarChart3', 'order' => 3, 'is_active' => true]
        );

        $repSubmodules = [
            ['slug' => 'stock', 'name' => 'Existencias', 'icon' => 'Boxes', 'order' => 1],
            ['slug' => 'movimientos', 'name' => 'Movimientos', 'icon' => 'History', 'order' => 2],
            ['slug' => 'valorizado', 'name' => 'Valorizado', 'icon' => 'DollarSign', 'order' => 3],
        ];
        foreach ($repSubmodules as $sub) {
            Submodule::firstOrCreate(
                ['slug' => $sub['slug'], 'module_id' => $reportes->id],
                ['name' => $sub['name'], 'icon' => $sub['icon'], 'order' => $sub['order'], 'is_active' => true]
            );
        }

        // Módulo: Compras
        $compras = Module::firstOrCreate(
            ['slug' => 'compras', 'application_id' => $inventario->id],
            ['name' => 'Compras', 'icon' => 'ShoppingCart', 'order' => 4, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'ordenes-compra', 'module_id' => $compras->id],
            ['name' => 'Órdenes de Compra', 'icon' => 'FileText', 'order' => 1, 'is_active' => true]
        );
        Submodule::firstOrCreate(
            ['slug' => 'recepciones', 'module_id' => $compras->id],
            ['name' => 'Recepciones', 'icon' => 'PackageCheck', 'order' => 2, 'is_active' => true]
        );

        // ========================================
        // APLICACIÓN: VENTAS
        // ========================================
        $sales = Application::firstOrCreate(
            ['slug' => 'sales', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Ventas',
                'description' => 'Gestión de ventas y clientes',
                'icon' => 'ShoppingBag',
                'path' => "/{$enterprise->slug}/sales",
                'is_active' => true,
            ]
        );

        // Módulo: Clientes
        $clientes = Module::firstOrCreate(
            ['slug' => 'clientes', 'application_id' => $sales->id],
            ['name' => 'Clientes', 'icon' => 'Users', 'order' => 1, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'catalogo', 'module_id' => $clientes->id],
            ['name' => 'Catálogo de Clientes', 'icon' => 'UserCircle', 'order' => 1, 'is_active' => true]
        );

        // Módulo: Gestión de Producto
        $gestionProducto = Module::firstOrCreate(
            ['slug' => 'gestion-producto', 'application_id' => $sales->id],
            ['name' => 'Gestión de Producto', 'icon' => 'PackageSearch', 'order' => 2, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'calculadora-sscc', 'module_id' => $gestionProducto->id],
            ['name' => 'Calculadora SSCC', 'icon' => 'QrCode', 'order' => 1, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'etiquetas-sscc', 'module_id' => $gestionProducto->id],
            ['name' => 'Etiquetas SSCC', 'icon' => 'Tags', 'order' => 2, 'is_active' => true]
        );

        // ========================================
        // APLICACIÓN: EXPORTACIONES
        // ========================================
        $exports = Application::firstOrCreate(
            ['slug' => 'exports', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Exportaciones',
                'description' => 'Manejo de exportaciones',
                'icon' => 'Ship',
                'path' => "/{$enterprise->slug}/exports",
                'is_active' => true,
            ]
        );

        // Módulo: Embarques
        $embarques = Module::firstOrCreate(
            ['slug' => 'embarques', 'application_id' => $exports->id],
            ['name' => 'Embarques', 'icon' => 'Container', 'order' => 1, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'programacion', 'module_id' => $embarques->id],
            ['name' => 'Programación', 'icon' => 'Calendar', 'order' => 1, 'is_active' => true]
        );

        // ========================================
        // APLICACIÓN: COMPRAS DE FRUTA
        // ========================================
        $purchases = Application::firstOrCreate(
            ['slug' => 'purchases', 'enterprise_id' => $enterprise->id],
            [
                'name' => 'Compras de Fruta',
                'description' => 'Gestión de compras de fruta a productores',
                'icon' => 'Apple',
                'path' => "/{$enterprise->slug}/purchases",
                'is_active' => true,
            ]
        );

        // Módulo: Recepción
        $recepcion = Module::firstOrCreate(
            ['slug' => 'recepcion', 'application_id' => $purchases->id],
            ['name' => 'Recepción', 'icon' => 'PackageCheck', 'order' => 1, 'is_active' => true]
        );

        Submodule::firstOrCreate(
            ['slug' => 'tickets', 'module_id' => $recepcion->id],
            ['name' => 'Tickets de Recepción', 'icon' => 'Ticket', 'order' => 1, 'is_active' => true]
        );

        return $this->summarize('Comercio', $enterprise, $appsBefore, $modsBefore, $subsBefore);
    }

    protected function ensureSubmodulePermissionTypes(Module $module, string $submoduleSlug, array $types): void
    {
        $submodule = Submodule::where('module_id', $module->id)
            ->where('slug', $submoduleSlug)
            ->first();

        if (! $submodule) {
            return;
        }

        $order = (int) (SubmodulePermissionType::where('submodule_id', $submodule->id)->max('order') ?? 0);

        foreach ($types as $type) {
            $exists = SubmodulePermissionType::where('submodule_id', $submodule->id)
                ->where('slug', $type['slug'])
                ->exists();

            if ($exists) {
                continue;
            }

            $order++;

            SubmodulePermissionType::create([
                'submodule_id' => $submodule->id,
                'slug' => $type['slug'],
                'name' => $type['name'],
                'description' => $type['description'],
                'order' => $order,
                'is_active' => true,
            ]);
        }
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function countTree(Enterprise $enterprise): array
    {
        $appIds = Application::where('enterprise_id', $enterprise->id)->pluck('id');
        $modIds = Module::whereIn('application_id', $appIds)->pluck('id');
        $subCount = Submodule::whereIn('module_id', $modIds)->count();

        return [$appIds->count(), $modIds->count(), $subCount];
    }

    private function summarize(string $label, Enterprise $enterprise, int $appsBefore, int $modsBefore, int $subsBefore): array
    {
        [$appsAfter, $modsAfter, $subsAfter] = $this->countTree($enterprise);

        return [
            'application' => $label,
            'created' => [
                'applications' => $appsAfter - $appsBefore,
                'modules' => $modsAfter - $modsBefore,
                'submodules' => $subsAfter - $subsBefore,
            ],
        ];
    }
}
