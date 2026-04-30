<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    require 'vendor/autoload.php';

    if (!isset($_FILES['factura'])) {
        throw new Exception('No se recibió ningún archivo PDF.');
    }

    if ($_FILES['factura']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error al subir el archivo.');
    }

    $archivo = $_FILES['factura']['tmp_name'];

    if (!file_exists($archivo)) {
        throw new Exception('El archivo temporal no existe.');
    }

    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($archivo);
    $texto = $pdf->getText();

    $texto_limpio = preg_replace('/\s+/', ' ', $texto);

    preg_match('/RUC:\s*([0-9]{11})/i', $texto_limpio, $ruc_emisor);
    preg_match('/\b([A-Z][0-9]{3})[-\s]?([0-9]{1,8})\b/i', $texto_limpio, $numero);
    preg_match('/Fecha de Emisión\s*:\s*([0-9]{2}\/[0-9]{2}\/[0-9]{4})/i', $texto_limpio, $fecha);
    preg_match('/Señor\(es\)\s*:\s*(.*?)\s+RUC\s*:/i', $texto_limpio, $cliente);
    preg_match('/Señor\(es\)\s*:.*?RUC\s*:\s*([0-9]{11})/i', $texto_limpio, $ruc_cliente);
    preg_match('/Tipo de Moneda\s*:\s*(.*?)\s+Observación/i', $texto_limpio, $moneda);
    preg_match('/Sub Total Ventas\s*:\s*S\/\s*([0-9.,]+)/i', $texto_limpio, $subtotal);
    preg_match('/IGV\s*:\s*S\/\s*([0-9.,]+)/i', $texto_limpio, $igv);
    preg_match('/Importe Total\s*:\s*S\/\s*([0-9.,]+)/i', $texto_limpio, $total);
    preg_match('/Importe Total\s*:\s*S\/\s*[0-9.,]+\s*-\s*(.*?)\s+SN\s+/i', $texto_limpio, $razon_social_emisor);

    $serie_numero = '';
    if (!empty($numero)) {
        $serie_numero = strtoupper($numero[1]) . '-' . $numero[2];
    }

     // =========================
    // DETALLE VENDIDO
    // =========================

    $detalle = [];

    $texto_detalle = $texto;

    // Convertir saltos, tabs y espacios raros
    $texto_detalle = str_replace("\xc2\xa0", " ", $texto_detalle);
    $texto_detalle = preg_replace('/[\r\n\t]+/', ' ', $texto_detalle);
    $texto_detalle = preg_replace('/\s+/', ' ', $texto_detalle);

    // Corregir casos pegados del PDF:
    // 65.05UNIDAD  => 65.05 UNIDAD
    $texto_detalle = preg_replace('/([0-9]+(?:[.,][0-9]+)?)(UNIDAD|UND|NIU|KG|KGM|KILOGRAMO|KILOGRAMOS|CAJA|BOLSA|PAQUETE|SERVICIO)/i', '$1 $2', $texto_detalle);

    // 0000QUESO => 0000 QUESO
    $texto_detalle = preg_replace('/\b([0-9]{3,})([A-ZÁÉÍÓÚÑ])/iu', '$1 $2', $texto_detalle);

    // Patrón para detectar el producto
    $patron = '/([0-9]+(?:[.,][0-9]+)?)\s+(UNIDAD|UND|NIU|KG|KGM|KILOGRAMO|KILOGRAMOS|CAJA|BOLSA|PAQUETE|SERVICIO)\s+([A-Z0-9]+)\s+(.+?)\s+([0-9]+(?:[.,][0-9]{2}))/iu';

    if (preg_match_all($patron, $texto_detalle, $items, PREG_SET_ORDER)) {
        foreach ($items as $m) {
            $descripcion = trim($m[4]);

            // Evita que agarre texto posterior al detalle
            $descripcion = preg_replace('/\s+Valor de Venta.*$/i', '', $descripcion);
            $descripcion = trim($descripcion);

         
            $detalle[] = [
                'producto_general' => '',
                'producto_especifico' => trim($m[4]),
                'unidad_medida' => trim($m[2]),
                'presentacion' => '',
                'descripcion' => trim($m[4]),
                'cantidad' => trim($m[1]),
                'valor_unitario' => trim($m[5])
            ];
        }
    }

    echo json_encode([
        'error' => false,
        'ruc_emisor' => $ruc_emisor[1] ?? '',
        'razon_social_emisor' => $razon_social_emisor[1] ?? '',
        'numero' => $serie_numero,
        'fecha' => $fecha[1] ?? '',
        'cliente' => $cliente[1] ?? '',
        'ruc_cliente' => $ruc_cliente[1] ?? '',
        'moneda' => trim($moneda[1] ?? ''),
        'subtotal' => $subtotal[1] ?? '',
        'igv' => $igv[1] ?? '',
        'total' => $total[1] ?? '',
        'detalle' => $detalle,
        'texto_pdf' => $texto,
        'texto_detalle_debug' => $texto_detalle,
        'serie' => $numero[1] ?? '',
        'correlativo' => $numero[2] ?? '',
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'error' => true,
        'mensaje' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}