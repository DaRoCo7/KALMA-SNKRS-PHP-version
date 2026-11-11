<?php
/**
 * Área interna: barra lateral (marca, secciones del rol, usuario y salida) y
 * el área de trabajo, que rellena el módulo de cada sección.
 *
 * No usa la cabecera ni el pie de la tienda: esto es una herramienta de
 * trabajo y la navegación de la tienda solo estorbaría. El rol, los permisos
 * y las transiciones de estado viajan en data-atributos para que el
 * JavaScript pinte solo lo que el usuario puede hacer.
 *
 * @var array<string, mixed>              $usuario
 * @var string                            $nombreRol
 * @var list<array<string, string>>       $secciones
 * @var list<string>                      $permisos
 * @var array<string, list<string>>       $transiciones
 */
$estilos = ['/css/panel.css'];
$claseBody = 'pagina-panel';

// Agrupadas por «grupo» conservando el orden de la lista del controlador
$grupos = [];
foreach ($secciones as $seccion) {
    $grupos[$seccion['grupo']][] = $seccion;
}

require dirname(__DIR__) . '/parciales/documento-inicio.php';
?>
<div class="panel" id="panel"
     data-rol="<?= e($usuario['rol']) ?>"
     data-permisos="<?= e(json_encode($permisos, JSON_THROW_ON_ERROR)) ?>"
     data-transiciones="<?= e(json_encode($transiciones, JSON_THROW_ON_ERROR)) ?>">

    <aside class="panel__lateral">
        <div class="panel__cabeza">
            <a class="panel__marca" href="/panel">
                <img src="/img/logo-kalma.png" alt="KALMA SNKRS, inicio del panel" width="640" height="275">
            </a>
            <span class="panel__ambito">Área interna</span>
            <?php // Solo en el móvil; sin JavaScript la navegación se queda abierta y no hace falta ?>
            <button type="button" class="panel__boton-menu" aria-expanded="false" aria-controls="panel-navegacion">
                Secciones
            </button>
        </div>

        <div class="panel__desplegable" id="panel-navegacion">
            <nav class="panel__nav" aria-label="Secciones del panel">
                <?php foreach ($grupos as $grupo => $deGrupo): ?>
                    <p class="panel__grupo"><?= e($grupo) ?></p>
                    <ul>
                        <?php foreach ($deGrupo as $seccion): ?>
                            <li>
                                <a href="#<?= e($seccion['id']) ?>" data-seccion="<?= e($seccion['id']) ?>"><?= e($seccion['nombre']) ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </nav>

            <div class="panel__pie">
                <p class="panel__usuario">
                    <span class="panel__usuario-nombre"><?= e(trim($usuario['nombre'] . ' ' . $usuario['apellidos'])) ?></span>
                    <span class="panel__usuario-rol"><?= e($nombreRol) ?></span>
                </p>
                <a class="panel__enlace" href="/">Ver tienda</a>
                <form method="post" action="/logout">
                    <input type="hidden" name="_csrf" value="<?= e(\App\Seguridad\Sesion::tokenCsrf()) ?>">
                    <button type="submit" class="panel__enlace">Cerrar sesión</button>
                </form>
            </div>
        </div>
    </aside>

    <section class="panel__trabajo" id="panel-trabajo" tabindex="-1" aria-labelledby="panel-titulo">
        <noscript>
            <p class="panel__aviso">El panel necesita JavaScript para cargar los pedidos y las cifras.</p>
        </noscript>
    </section>

    <?php // Confirmaciones breves («Pedido confirmado»): las anuncia el lector de pantalla sin mover el foco ?>
    <p class="panel__nota" id="panel-nota" role="status" aria-live="polite"></p>
</div>
<script type="module" src="/js/panel/panel.js"></script>
<?php require dirname(__DIR__) . '/parciales/documento-fin.php'; ?>
