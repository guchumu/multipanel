<?php
/** @var array{enforcement_enabled: bool, default_max_streams: int, default_max_away_streams: int, kill_message: string, count_mode: string, sandbox_alerts: bool} $settings */
/** @var string $effectiveKillMessage */
ob_start();
?>
<div class="mb-4">
    <a href="/settings" class="text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Configuración</a>
    <h4 class="mb-0 mt-1">Límite de streams: casa / fuera</h4>
    <p class="text-muted small mb-0">
        Por defecto: <strong>2 teles en casa</strong> y <strong>0 fuera</strong>.
        El corte automático queda apagado hasta que lo actives tú. Mientras tanto, WhatsApp/Telegram te avisan
        <em>cuándo se habría cortado</em> y el motivo (otra casa o demasiadas teles).
    </p>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="/settings/stream-limits">
                    <?= csrf_field() ?>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="enforcement_enabled" name="enforcement_enabled" value="1"
                               <?= !empty($settings['enforcement_enabled']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enforcement_enabled">
                            <strong>Cortar de verdad</strong>
                            <div class="small text-muted">Apagado = solo sandbox (te avisa, no corta). Enciéndelo cuando quieras aplicar el corte.</div>
                        </label>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input type="hidden" name="sandbox_alerts" value="0">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="sandbox_alerts" name="sandbox_alerts" value="1"
                               <?= !empty($settings['sandbox_alerts']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="sandbox_alerts">
                            <strong>Avisarme en sandbox</strong>
                            <div class="small text-muted">WhatsApp/Telegram cuando alguien se pasa: hora, usuario, Casa/Fuera y qué se habría cortado.</div>
                        </label>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="default_max_streams">En casa (por defecto)</label>
                            <input type="number" min="1" max="50" class="form-control"
                                   id="default_max_streams" name="default_max_streams"
                                   value="<?= (int) ($settings['default_max_streams'] ?? 2) ?>">
                            <div class="form-text">Recomendado: 2. Cada tele cuenta 1.</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="default_max_away_streams">Fuera (por defecto)</label>
                            <input type="number" min="0" max="20" class="form-control"
                                   id="default_max_away_streams" name="default_max_away_streams"
                                   value="<?= (int) ($settings['default_max_away_streams'] ?? 0) ?>">
                            <div class="form-text">0 = no se puede usar en otra casa / WAN.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="count_mode">Cómo contar</label>
                        <select class="form-select" id="count_mode" name="count_mode">
                            <option value="household" <?= ($settings['count_mode'] ?? 'household') !== 'sessions' ? 'selected' : '' ?>>
                                Casa / fuera (recomendado)
                            </option>
                            <option value="sessions" <?= ($settings['count_mode'] ?? '') === 'sessions' ? 'selected' : '' ?>>
                                Cada sesión (sin distinguir hogar)
                            </option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="kill_message">Mensaje genérico al cortar (opcional)</label>
                        <textarea class="form-control" id="kill_message" name="kill_message" rows="2"
                                  maxlength="500"
                                  placeholder="Vacío = mensajes distintos para «otra casa» y «demasiadas teles»"><?= e($settings['kill_message'] ?? '') ?></textarea>
                        <div class="form-text">
                            Fuera: «esta cuenta solo se puede usar en casa». Casa: «demasiadas reproducciones a la vez».
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Guardar
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="mb-2">Cómo funciona</h6>
                <ul class="small text-muted mb-3 ps-3">
                    <li>Casa = LAN o IP/dispositivo marcado Hogar en la ficha.</li>
                    <li>Fuera = WAN / otra IP no marcada.</li>
                    <li>El corte no arranca solo: tú lo activas aquí.</li>
                    <li>Sandbox: te llega el momento exacto y el motivo.</li>
                    <li>Cron <code>streams</code> o <code>all</code> — mejor cada 1–2 min para no llegar tarde.</li>
                </ul>
                <a href="/media-users/stream-violations" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-exclamation-octagon me-1"></i>Ver incumplimientos
                </a>
                <a href="/settings/stop-messages" class="btn btn-outline-secondary btn-sm ms-1">
                    Mensajes al detener
                </a>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include base_path('resources/views/layouts/app.php'); ?>
