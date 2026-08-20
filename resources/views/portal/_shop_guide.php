<?php
$included = (int) ($included ?? 2);
$extraAccount = (float) ($extraAccount ?? 50);
$extraStreamMonth = (float) ($extraStreamMonth ?? 4);
$eur = static fn (float $n): string => number_format($n, 2, ',', '.') . ' €';
?>
<section class="card portal-card ez-guide" aria-labelledby="ez-guide-title">
    <div class="card-body">
        <h2 class="ez-step-title" id="ez-guide-title">Qué estás contratando</h2>
        <p class="ez-help mb-3">
            Una <strong>cuenta individual</strong> es un usuario con su propio historial.
            Incluye <strong><?= $included ?> reproducciones a la vez en el mismo hogar</strong>
            (tele + tablet, dos teles en el salón…).
        </p>

        <div class="ez-scenes">
            <article class="ez-scene ez-scene--yes">
                <div class="ez-scene-art" aria-hidden="true">
                    <svg viewBox="0 0 280 168" role="img">
                        <rect width="280" height="168" rx="16" fill="#e8f7ee"/>
                        <circle cx="232" cy="28" r="14" fill="#ffd56a"/>
                        <path d="M48 86 L140 28 L232 86 Z" fill="#1b3a6b"/>
                        <rect x="68" y="86" width="144" height="62" rx="4" fill="#f4f8fc"/>
                        <rect x="78" y="98" width="52" height="34" rx="3" fill="#16324f"/>
                        <polygon points="96,107 96,123 112,115" fill="#7ee0b0"/>
                        <rect x="150" y="98" width="52" height="34" rx="3" fill="#16324f"/>
                        <polygon points="168,107 168,123 184,115" fill="#7ee0b0"/>
                        <rect x="128" y="148" width="24" height="8" fill="#c5d4e3"/>
                        <text x="140" y="24" text-anchor="middle" font-size="13" font-weight="800" fill="#1f8a5b">MISMA CASA</text>
                    </svg>
                </div>
                <span class="ez-stamp ez-stamp--yes">Sí</span>
                <h3>2 a la vez en casa</h3>
                <p>Dos teles, o tele y móvil, en el mismo hogar. Ven a la vez; el historial es el de esta cuenta.</p>
            </article>

            <article class="ez-scene ez-scene--no">
                <div class="ez-scene-art" aria-hidden="true">
                    <svg viewBox="0 0 280 168" role="img">
                        <rect width="280" height="168" rx="16" fill="#fdeeee"/>
                        <path d="M22 98 L70 58 L118 98 Z" fill="#1b3a6b"/>
                        <rect x="36" y="98" width="68" height="42" rx="3" fill="#f4f8fc"/>
                        <rect x="48" y="108" width="44" height="22" rx="2" fill="#16324f"/>
                        <polygon points="62,113 62,125 74,119" fill="#7ee0b0"/>
                        <rect x="162" y="48" width="96" height="92" rx="4" fill="#3b7dd8"/>
                        <rect x="174" y="60" width="22" height="16" fill="#eaf4ff"/>
                        <rect x="204" y="60" width="22" height="16" fill="#eaf4ff"/>
                        <rect x="234" y="60" width="14" height="16" fill="#eaf4ff"/>
                        <rect x="174" y="84" width="22" height="16" fill="#16324f"/>
                        <polygon points="181,87 181,97 191,92" fill="#ffd56a"/>
                        <rect x="204" y="84" width="22" height="16" fill="#eaf4ff"/>
                        <rect x="174" y="108" width="22" height="16" fill="#eaf4ff"/>
                        <rect x="204" y="108" width="22" height="16" fill="#eaf4ff"/>
                        <line x1="128" y1="70" x2="156" y2="118" stroke="#c0392b" stroke-width="8" stroke-linecap="round"/>
                        <line x1="156" y1="70" x2="128" y2="118" stroke="#c0392b" stroke-width="8" stroke-linecap="round"/>
                        <text x="140" y="24" text-anchor="middle" font-size="13" font-weight="800" fill="#c0392b">CASA + PISO</text>
                    </svg>
                </div>
                <span class="ez-stamp ez-stamp--no">No</span>
                <h3>2 a la vez fuera de casa</h3>
                <p>No vale casa y piso, ni casa y casa de un amigo, a la vez con la misma cuenta.</p>
            </article>
        </div>

        <table class="ez-rules">
            <caption>Qué puede y qué no puede esta cuenta</caption>
            <thead>
                <tr>
                    <th>Situación</th>
                    <th>¿Puede?</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>2 reproducciones a la vez en la misma casa</td>
                    <td><span class="ez-yn ez-yn--yes">Sí</span></td>
                </tr>
                <tr>
                    <td>Tele y móvil a la vez en casa</td>
                    <td><span class="ez-yn ez-yn--yes">Sí</span></td>
                </tr>
                <tr>
                    <td>3 o más teles a la vez en casa</td>
                    <td><span class="ez-yn ez-yn--yes">Sí, con reproducción extra (<?= e($eur($extraStreamMonth)) ?>/mes)</span></td>
                </tr>
                <tr>
                    <td>Ver a la vez en casa y en un piso / otro sitio</td>
                    <td><span class="ez-yn ez-yn--no">No</span></td>
                </tr>
                <tr>
                    <td>Cada uno su historial y “continuar viendo”</td>
                    <td><span class="ez-yn ez-yn--yes">Sí, con otra cuenta individual (<?= e($eur($extraAccount)) ?>)</span></td>
                </tr>
                <tr>
                    <td>Prestar la cuenta: ves lo mismo (listas, progreso)</td>
                    <td><span class="ez-yn ez-yn--no">No es una cuenta por persona</span></td>
                </tr>
            </tbody>
        </table>
    </div>
</section>
