<?php
/** @var string $msgConfigTitle */
/** @var string $msgConfigBody */
/** @var string $msgKillGeneric */
/** @var string $msgKillHome */
/** @var string $msgKillAway */
/** @var int $maxHome */
/** @var int $maxAway */

ob_start();
?>
<div class="help-cs d-print-block">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4 d-print-none">
        <div>
            <h4 class="mb-1">Guía: atención al cliente</h4>
            <p class="text-muted small mb-0">
                Cortes de reproducción, Transcode y límites de pantallas.
                Textos actuales de tu tenant (si los cambias en Ajustes, esta página se actualiza).
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="/activity" class="btn btn-outline-secondary btn-sm"><i class="bi bi-broadcast-pin me-1"></i>En directo</a>
            <a href="/settings/stop-messages" class="btn btn-outline-secondary btn-sm">Mensajes al detener</a>
            <a href="/settings/stream-limits" class="btn btn-outline-secondary btn-sm">Límite streams</a>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Imprimir / PDF
            </button>
        </div>
    </div>

    <div class="d-none d-print-block mb-3">
        <h2 class="h4 mb-1">MultiPanel — Guía atención al cliente</h2>
        <p class="small text-muted">Cortes, Transcode y límites de pantallas</p>
    </div>

    <div class="alert alert-info border-0 shadow-sm small">
        <strong>Idea clave:</strong> solo se avisa/corta el Transcode de vídeo
        <em>salvable</em> (bajada de calidad / mal ajuste). Se deja pasar Burn de subtítulos
        y cambio de codec cuando el dispositivo no acepta el fichero.
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted">Límite casa (defecto tenant)</div>
                    <div class="fs-4 fw-semibold"><?= (int) $maxHome ?> pantallas</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted">Límite fuera</div>
                    <div class="fs-4 fw-semibold"><?= (int) $maxAway ?> pantallas</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted">Checklist rápido</div>
                    <ol class="small mb-0 ps-3">
                        <li>¿Qué mensaje exacto salió?</li>
                        <li>Configuración → calidad Original</li>
                        <li>Casa / fuera / teles → límites</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3 help-cs-section">
        <div class="card-body">
            <h5 class="card-title">1. «<?= e($msgConfigTitle) ?>»</h5>
            <blockquote class="blockquote small border-start border-3 border-warning ps-3 mb-3">
                <?= e($msgConfigBody) ?>
            </blockquote>
            <p class="mb-2"><strong>Qué significa:</strong> Transcode de vídeo <em>salvable</em>
                (casi siempre calidad demasiado baja). El panel corta para no forzar la GPU.</p>
            <p class="mb-1"><strong>Solución (Plex):</strong></p>
            <ol class="small">
                <li>Ajustes de la app Plex (tele / móvil / Fire Stick).</li>
                <li>Calidad / Remote Streaming / Reproducción remota.</li>
                <li>En casa: <strong>Original</strong> o máxima. Fuera: lo más alto posible.</li>
                <li>Activar Direct Play / Direct Stream; evitar «Convertir automáticamente».</li>
                <li>Cerrar Plex del todo y reabrir el contenido.</li>
                <li>Bien = Direct Play o Direct Stream. Mal = Transcode 1080p→720p.</li>
            </ol>
            <div class="bg-light rounded p-2 small">
                <strong>Frase para el cliente:</strong>
                «No es que la película esté mal: la app pide una calidad más baja y el servidor convierte el vídeo.
                Sube la calidad a Original y no debería cortarse.»
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3 help-cs-section">
        <div class="card-body">
            <h5 class="card-title">2. Demasiadas teles en casa</h5>
            <blockquote class="blockquote small border-start border-3 border-danger ps-3 mb-3">
                <?= e($msgKillHome) ?>
            </blockquote>
            <p class="mb-2"><strong>Qué significa:</strong> más pantallas en casa de las permitidas
                (ahora: <strong><?= (int) $maxHome ?></strong>).</p>
            <ol class="small mb-0">
                <li>Preguntar cuántas pantallas hay encendidas.</li>
                <li>Cerrar las que no usen.</li>
                <li>Si necesitan más: ampliar plan / límite (administración).</li>
            </ol>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3 help-cs-section">
        <div class="card-body">
            <h5 class="card-title">3. Uso fuera de casa / otra casa</h5>
            <blockquote class="blockquote small border-start border-3 border-warning ps-3 mb-3">
                <?= e($msgKillAway) ?>
            </blockquote>
            <p class="mb-2"><strong>Qué significa:</strong> ve fuera del hogar y la cuenta no lo permite
                (límite fuera: <strong><?= (int) $maxAway ?></strong>).</p>
            <ol class="small mb-0">
                <li>¿Casa (Wi‑Fi) o fuera (datos / hotel / otra vivienda)?</li>
                <li>Si dice estar en casa: router, sin datos móviles, sin VPN; a veces hay que actualizar IP de hogar.</li>
                <li>Si está fuera: plan con streams fuera, o no usar en remoto.</li>
            </ol>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3 help-cs-section">
        <div class="card-body">
            <h5 class="card-title">4. Límite genérico de streams</h5>
            <blockquote class="blockquote small border-start border-3 border-secondary ps-3 mb-3">
                <?= e($msgKillGeneric) ?>
            </blockquote>
            <p class="small mb-0">Misma lógica que el punto 2: cerrar pantallas o ampliar plan.</p>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3 help-cs-section">
        <div class="card-body">
            <h5 class="card-title">5. Casos que <em>no</em> debe cortar el panel</h5>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>En directo</th>
                            <th>¿Culpa del cliente?</th>
                            <th>Qué decir</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <tr>
                            <td>Video Transcode + Subtitle <strong>Burn</strong></td>
                            <td>No / poco</td>
                            <td>Probar subtítulos soft o sin subtítulos. Se deja pasar.</td>
                        </tr>
                        <tr>
                            <td>Video Transcode <strong>HEVC → H264</strong> (u otro codec)</td>
                            <td>Dispositivo</td>
                            <td>La tele no reproduce ese formato directo. Se deja pasar.</td>
                        </tr>
                        <tr>
                            <td>Solo Container Converting, Video Direct Stream</td>
                            <td>No</td>
                            <td>Remux ligero; no es el corte por calidad.</td>
                        </tr>
                        <tr>
                            <td>Solo Audio Transcode, Video Direct Play</td>
                            <td>No</td>
                            <td>No corta por eso.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3 help-cs-section">
        <div class="card-body">
            <h5 class="card-title">6. Quejas frecuentes (sin mensaje de corte)</h5>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Síntoma</th>
                            <th>Causa</th>
                            <th>Solución</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <tr>
                            <td>Corte ~30 s + mensaje configuración</td>
                            <td>Calidad baja → Transcode salvable</td>
                            <td>Punto 1 (Original)</td>
                        </tr>
                        <tr>
                            <td>Pixelado / buffering</td>
                            <td>Wi‑Fi flojo o calidad alta</td>
                            <td>Router / cable; bajar calidad solo si no le cortan</td>
                        </tr>
                        <tr>
                            <td>En casa le trata como fuera</td>
                            <td>IP / datos / VPN</td>
                            <td>Wi‑Fi casa; soporte revisa IP hogar</td>
                        </tr>
                        <tr>
                            <td>Varias pantallas, corta una</td>
                            <td>Límite streams</td>
                            <td>Punto 2</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3 help-cs-section">
        <div class="card-body">
            <h5 class="card-title">7. Textos WhatsApp / chat (copiar)</h5>
            <div class="mb-3">
                <div class="small text-muted mb-1">Calidad / Transcode</div>
                <pre class="bg-light rounded p-3 small mb-0 help-cs-copy">Hola. El corte es porque la app está pidiendo una calidad más baja y el servidor tiene que convertir el vídeo. En Plex: Ajustes → Calidad / Reproducción → pon «Original» o la máxima (en casa y fuera). Cierra Plex y vuelve a abrir. Si sigue igual, mándanos captura de Ajustes → Calidad.</pre>
            </div>
            <div class="mb-3">
                <div class="small text-muted mb-1">Límite en casa</div>
                <pre class="bg-light rounded p-3 small mb-0 help-cs-copy">Tu plan permite un número limitado de pantallas a la vez en casa. Cierra alguna reproducción o, si necesitáis más, os ampliamos el plan.</pre>
            </div>
            <div>
                <div class="small text-muted mb-1">Fuera de casa</div>
                <pre class="bg-light rounded p-3 small mb-0 help-cs-copy">Esta cuenta no permite ver fuera del hogar (o el límite fuera es 0). Si estás en casa con Wi‑Fi y aun así corta, reinicia el router y avísanos para revisar la IP.</pre>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .app-shell .sidebar,
    .navbar,
    .offcanvas,
    .d-print-none { display: none !important; }
    .app-content { padding: 0 !important; }
    .help-cs-section { break-inside: avoid; box-shadow: none !important; border: 1px solid #ddd !important; }
    .help-cs-copy { white-space: pre-wrap; }
}
.help-cs-copy { white-space: pre-wrap; word-break: break-word; }
</style>
<?php
$content = ob_get_clean();
include base_path('resources/views/layouts/app.php');
