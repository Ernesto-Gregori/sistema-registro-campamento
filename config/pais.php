<?php
// config/pais.php — Ahora lee de la BD
// Los valores se configuran desde admin/configuracion.php

function obtenerEstados(): array {
    global $pdo;
    
    $paises_estados = [
        'El Salvador' => [
            'division' => 'Departamento',
            'estados'  => [
                'Ahuachapán','Sonsonate','Santa Ana','La Libertad',
                'Chalatenango','San Salvador','Cuscatlán','La Paz',
                'San Vicente','Cabañas','Usulután','San Miguel',
                'Morazán','La Unión'
            ]
        ],
        'Guatemala' => [
            'division' => 'Departamento',
            'estados'  => [
                'Alta Verapaz','Baja Verapaz','Chimaltenango','Chiquimula',
                'El Progreso','Escuintla','Guatemala','Huehuetenango',
                'Izabal','Jalapa','Jutiapa','Petén','Quetzaltenango',
                'Quiché','Retalhuleu','Sacatepéquez','San Marcos',
                'Santa Rosa','Sololá','Suchitepéquez','Totonicapán','Zacapa'
            ]
        ],
        'Honduras' => [
            'division' => 'Departamento',
            'estados'  => [
                'Atlántida','Choluteca','Colón','Comayagua','Copán',
                'Cortés','El Paraíso','Francisco Morazán','Gracias a Dios',
                'Intibucá','Islas de la Bahía','La Paz','Lempira','Ocotepeque',
                'Olancho','Santa Bárbara','Valle','Yoro'
            ]
        ],
        'Nicaragua' => [
            'division' => 'Departamento',
            'estados'  => [
                'Boaco','Carazo','Chinandega','Chontales','Estelí',
                'Granada','Jinotega','León','Madriz','Managua',
                'Masaya','Matagalpa','Nueva Segovia','Río San Juan',
                'Rivas','RACN','RACS'
            ]
        ],
        'Costa Rica' => [
            'division' => 'Provincia',
            'estados'  => [
                'San José','Alajuela','Cartago','Heredia',
                'Guanacaste','Puntarenas','Limón'
            ]
        ],
        'Panamá' => [
            'division' => 'Provincia',
            'estados'  => [
                'Bocas del Toro','Chiriquí','Coclé','Colón',
                'Darién','Herrera','Los Santos','Panamá',
                'Panamá Oeste','Veraguas','Guna Yala','Emberá',
                'Ngäbe-Buglé'
            ]
        ],
        'México' => [
            'division' => 'Estado',
            'estados'  => [
                'Aguascalientes','Baja California','Baja California Sur',
                'Campeche','Chiapas','Chihuahua','Ciudad de México',
                'Coahuila','Colima','Durango','Guanajuato','Guerrero',
                'Hidalgo','Jalisco','México','Michoacán','Morelos',
                'Nayarit','Nuevo León','Oaxaca','Puebla','Querétaro',
                'Quintana Roo','San Luis Potosí','Sinaloa','Sonora',
                'Tabasco','Tamaulipas','Tlaxcala','Veracruz','Yucatán','Zacatecas'
            ]
        ],
        'Colombia' => [
            'division' => 'Departamento',
            'estados'  => [
                'Amazonas','Antioquia','Arauca','Atlántico','Bolívar',
                'Boyacá','Caldas','Caquetá','Casanare','Cauca','Cesar',
                'Chocó','Córdoba','Cundinamarca','Guainía','Guaviare',
                'Huila','La Guajira','Magdalena','Meta','Nariño',
                'Norte de Santander','Putumayo','Quindío','Risaralda',
                'San Andrés','Santander','Sucre','Tolima',
                'Valle del Cauca','Vaupés','Vichada'
            ]
        ],
        'Venezuela' => [
            'division' => 'Estado',
            'estados'  => [
                'Amazonas','Anzoátegui','Apure','Aragua','Barinas',
                'Bolívar','Carabobo','Cojedes','Delta Amacuro','Falcón',
                'Guárico','Lara','Mérida','Miranda','Monagas',
                'Nueva Esparta','Portuguesa','Sucre','Táchira','Trujillo',
                'Vargas','Yaracuy','Zulia','Distrito Capital'
            ]
        ],
        'Perú' => [
            'division' => 'Región',
            'estados'  => [
                'Amazonas','Áncash','Apurímac','Arequipa','Ayacucho',
                'Cajamarca','Callao','Cusco','Huancavelica','Huánuco',
                'Ica','Junín','La Libertad','Lambayeque','Lima',
                'Loreto','Madre de Dios','Moquegua','Pasco','Piura',
                'Puno','San Martín','Tacna','Tumbes','Ucayali'
            ]
        ],
        'Chile' => [
            'division' => 'Región',
            'estados'  => [
                'Arica y Parinacota','Tarapacá','Antofagasta','Atacama',
                'Coquimbo','Valparaíso','Metropolitana','O\'Higgins',
                'Maule','Ñuble','Biobío','La Araucanía','Los Ríos',
                'Los Lagos','Aysén','Magallanes'
            ]
        ],
        'Argentina' => [
            'division' => 'Provincia',
            'estados'  => [
                'Buenos Aires','Catamarca','Chaco','Chubut','Córdoba',
                'Corrientes','Entre Ríos','Formosa','Jujuy','La Pampa',
                'La Rioja','Mendoza','Misiones','Neuquén','Río Negro',
                'Salta','San Juan','San Luis','Santa Cruz','Santa Fe',
                'Santiago del Estero','Tierra del Fuego','Tucumán',
                'Ciudad de Buenos Aires'
            ]
        ],
        'Ecuador' => [
            'division' => 'Provincia',
            'estados'  => [
                'Azuay','Bolívar','Cañar','Carchi','Chimborazo',
                'Cotopaxi','El Oro','Esmeraldas','Galápagos','Guayas',
                'Imbabura','Loja','Los Ríos','Manabí','Morona Santiago',
                'Napo','Orellana','Pastaza','Pichincha','Santa Elena',
                'Santo Domingo','Sucumbíos','Tungurahua','Zamora Chinchipe'
            ]
        ],
        'Bolivia' => [
            'division' => 'Departamento',
            'estados'  => [
                'Beni','Chuquisaca','Cochabamba','La Paz',
                'Oruro','Pando','Potosí','Santa Cruz','Tarija'
            ]
        ],
        'Paraguay' => [
            'division' => 'Departamento',
            'estados'  => [
                'Alto Paraguay','Alto Paraná','Amambay','Boquerón',
                'Caaguazú','Caazapá','Canindeyú','Central','Concepción',
                'Cordillera','Guairá','Itapúa','Misiones','Ñeembucú',
                'Paraguarí','Presidente Hayes','San Pedro','Asunción'
            ]
        ],
        'Uruguay' => [
            'division' => 'Departamento',
            'estados'  => [
                'Artigas','Canelones','Cerro Largo','Colonia','Durazno',
                'Flores','Florida','Lavalleja','Maldonado','Montevideo',
                'Paysandú','Río Negro','Rivera','Rocha','Salto',
                'San José','Soriano','Tacuarembó','Treinta y Tres'
            ]
        ],
        'Cuba' => [
            'division' => 'Provincia',
            'estados'  => [
                'Artemisa','Camagüey','Ciego de Ávila','Cienfuegos',
                'Granma','Guantánamo','Holguín','La Habana','Las Tunas',
                'Matanzas','Mayabeque','Pinar del Río','Sancti Spíritus',
                'Santiago de Cuba','Villa Clara','Isla de la Juventud'
            ]
        ],
        'República Dominicana' => [
            'division' => 'Provincia',
            'estados'  => [
                'Azua','Bahoruco','Barahona','Dajabón','Distrito Nacional',
                'Duarte','El Seibo','Elías Piña','Espaillat','Hato Mayor',
                'Hermanas Mirabal','Independencia','La Altagracia','La Romana',
                'La Vega','María Trinidad Sánchez','Monseñor Nouel','Monte Cristi',
                'Monte Plata','Pedernales','Peravia','Puerto Plata','Samaná',
                'San Cristóbal','San José de Ocoa','San Juan','San Pedro de Macorís',
                'Sánchez Ramírez','Santiago','Santiago Rodríguez','Santo Domingo',
                'Valverde'
            ]
        ],
    ];

    // Leer país desde BD
    try {
        global $pdo;
        $stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'pais'");
        $stmt->execute();
        $pais_bd = $stmt->fetchColumn();
        $pais_bd = $pais_bd ?: 'El Salvador';
    } catch (Exception $e) {
        $pais_bd = 'El Salvador';
    }

    return $paises_estados[$pais_bd]['estados'] ?? $paises_estados['El Salvador']['estados'];
}

function etiquetaDivision(): string {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'division_territorial'");
        $stmt->execute();
        $div = $stmt->fetchColumn();
        return ($div ?: 'Departamento') . ' de origen';
    } catch (Exception $e) {
        return 'Departamento de origen';
    }
}

// Constantes para compatibilidad con código existente
// que use PAIS_NOMBRE o PAIS_DIVISION directamente
try {
    global $pdo;
    $stmt = $pdo->query("SELECT clave, valor FROM configuracion 
                         WHERE clave IN ('pais','division_territorial')");
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $pais_nombre = $rows['pais']                ?? 'El Salvador';
    $pais_div    = $rows['division_territorial'] ?? 'Departamento';
} catch (Exception $e) {
    $pais_nombre = 'El Salvador';
    $pais_div    = 'Departamento';
}

if (!defined('PAIS_NOMBRE'))   define('PAIS_NOMBRE',   $pais_nombre);
if (!defined('PAIS_DIVISION'))  define('PAIS_DIVISION',  $pais_div);
?>