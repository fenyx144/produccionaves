<?php
declare(strict_types=1);

/**
 * Modal reutilizable: selección de granjas y campañas (árbol por zona/subzona).
 *
 * Variables (definir antes del require):
 * - string $gmc_id_prefix   Prefijo IDs, p. ej. 'hc', 'evf' (solo letras/números/guiones).
 * - string $gmc_shell_panel_id  id del contenedor .modal-panel (p. ej. 'hc-modal', 'evf-modal-granjas').
 * - string $gmc_body_intro_html  HTML opcional bajo el título (solo desde PHP de confianza).
 * - bool   $gmc_show_chk_todas  Mostrar «Seleccionar todas las granjas» (modo multi; default true).
 * - bool   $gmc_show_periodo_campanias  Mini filtro colapsado de vigencia de campañas (default false).
 * - string $gmc_panel_extra_class  Clases CSS extra en .modal-panel (p. ej. z-index helpers).
 */
$gmc_id_prefix = isset($gmc_id_prefix) ? (string) $gmc_id_prefix : 'hc';
if (!preg_match('/^[a-z][a-z0-9_-]{0,40}$/i', $gmc_id_prefix)) {
    $gmc_id_prefix = 'hc';
}
$gmc_shell_panel_id = isset($gmc_shell_panel_id) ? trim((string) $gmc_shell_panel_id) : ($gmc_id_prefix . '-modal');
if ($gmc_shell_panel_id === '' || !preg_match('/^[a-z][a-z0-9_-]{0,60}$/i', $gmc_shell_panel_id)) {
    $gmc_shell_panel_id = $gmc_id_prefix . '-modal';
}
$gmc_body_intro_html = isset($gmc_body_intro_html) ? (string) $gmc_body_intro_html : '';
$gmc_show_chk_todas = isset($gmc_show_chk_todas) ? (bool) $gmc_show_chk_todas : true;
$gmc_show_periodo_campanias = isset($gmc_show_periodo_campanias) ? (bool) $gmc_show_periodo_campanias : false;
$gmc_panel_extra_class = isset($gmc_panel_extra_class) ? trim((string) $gmc_panel_extra_class) : '';

$p = $gmc_id_prefix;
$gmc_periodo_desde = date('Y-m-d', strtotime('-1 year'));
$gmc_periodo_hasta = date('Y-m-d');
$gmc_backdrop_id = $p . '-backdrop';
$gmc_close_id = $p . '-modal-close';
$gmc_buscar_id = $p . '-buscar';
$gmc_chk_todas_id = $p . '-chk-todas';
$gmc_lista_id = $p . '-lista';
$gmc_btn_cancel_id = $p . '-btn-cancel';
$gmc_btn_apply_id = $p . '-btn-apply';

$gmc_h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
$gmc_panel_classes = 'modal-panel lay-hidden gmc-granjas-modal';
if ($gmc_panel_extra_class !== '') {
    $gmc_panel_classes .= ' ' . $gmc_panel_extra_class;
}
?>
<div id="<?php echo $gmc_h($gmc_backdrop_id); ?>" class="modal-backdrop lay-hidden"></div>
<div id="<?php echo $gmc_h($gmc_shell_panel_id); ?>" class="<?php echo $gmc_h($gmc_panel_classes); ?>">
    <div class="bg-white rounded-xl shadow-xl border border-gray-200 max-w-3xl w-full max-h-[90vh] flex flex-col overflow-hidden">
        <div class="modal-granjas-head">
            <h4 class="text-sm font-semibold text-gray-800 m-0">Granjas y campañas</h4>
            <button type="button" id="<?php echo $gmc_h($gmc_close_id); ?>" class="btn-eg-close-x" title="Cerrar" aria-label="Cerrar">&times;</button>
        </div>
        <div class="modal-granjas-body text-sm">
            <?php if ($gmc_body_intro_html !== '') { ?>
                <div class="gmc-granjas-modal-intro"><?php echo $gmc_body_intro_html; ?></div>
            <?php } ?>
            <?php if ($gmc_show_periodo_campanias) { ?>
            <div class="gmc-periodo-camp-wrap mb-3 shrink-0">
                <button type="button" id="<?php echo $gmc_h($p . '-periodo-camp-toggle'); ?>" class="gmc-periodo-camp-toggle w-full" aria-expanded="false" aria-controls="<?php echo $gmc_h($p . '-periodo-camp-body'); ?>">
                    <i class="fas fa-calendar-alt text-blue-600" aria-hidden="true"></i>
                    <span class="gmc-periodo-camp-toggle-label">Campañas presentes en:</span>
                    <span id="<?php echo $gmc_h($p . '-periodo-camp-resumen'); ?>" class="gmc-periodo-camp-resumen"><?php echo $gmc_h($gmc_periodo_desde . ' – ' . $gmc_periodo_hasta); ?></span>
                    <i class="fas fa-chevron-down gmc-periodo-camp-chev" aria-hidden="true"></i>
                </button>
                <div id="<?php echo $gmc_h($p . '-periodo-camp-body'); ?>" class="gmc-periodo-camp-body lay-hidden">
                    <div class="gmc-periodo-camp-fields">
                        <div class="gmc-periodo-camp-field">
                            <label class="gmc-periodo-camp-lbl" for="<?php echo $gmc_h($p . '-periodo-camp-desde'); ?>">Desde</label>
                            <input type="date" id="<?php echo $gmc_h($p . '-periodo-camp-desde'); ?>" class="gmc-periodo-camp-input" value="<?php echo $gmc_h($gmc_periodo_desde); ?>">
                        </div>
                        <div class="gmc-periodo-camp-field">
                            <label class="gmc-periodo-camp-lbl" for="<?php echo $gmc_h($p . '-periodo-camp-hasta'); ?>">Hasta</label>
                            <input type="date" id="<?php echo $gmc_h($p . '-periodo-camp-hasta'); ?>" class="gmc-periodo-camp-input" value="<?php echo $gmc_h($gmc_periodo_hasta); ?>">
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>
            <input type="text" id="<?php echo $gmc_h($gmc_buscar_id); ?>" class="form-control mb-3" placeholder="Buscar granja por código o nombre..." autocomplete="off" aria-label="Buscar granja">
            <?php if ($gmc_show_chk_todas) { ?>
            <div class="shrink-0 mb-3">
                <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700 cursor-pointer" for="<?php echo $gmc_h($gmc_chk_todas_id); ?>">
                    <input type="checkbox" id="<?php echo $gmc_h($gmc_chk_todas_id); ?>" class="w-4 h-4 border-gray-300 text-gray-700">
                    <span>Seleccionar todas las granjas</span>
                </label>
            </div>
            <?php } ?>
            <div id="<?php echo $gmc_h($gmc_lista_id); ?>" class="modal-granjas-tree-scroll" role="tree" aria-label="Lista de zonas y granjas"></div>
        </div>
        <div class="modal-granjas-foot">
            <button type="button" id="<?php echo $gmc_h($gmc_btn_cancel_id); ?>" class="btn-eg-outline">Cancelar</button>
            <button type="button" id="<?php echo $gmc_h($gmc_btn_apply_id); ?>" class="btn-eg-primary-modal">Aplicar</button>
        </div>
    </div>
</div>
