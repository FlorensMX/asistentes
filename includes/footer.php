<?php
/**
 * includes/footer.php
 *
 * Cierra el layout abierto por includes/header.php y dibuja la barra de
 * navegación inferior (mobile-first), fija al pie de la pantalla.
 */

declare(strict_types=1);

$nav    = $GLOBALS['__nav']       ?? [];
$activa = $GLOBALS['__navActiva'] ?? '';
?>
  </main>

  <nav class="fixed bottom-0 inset-x-0 z-10 bg-white border-t shadow-[0_-1px_3px_rgba(0,0,0,0.05)]">
    <div class="max-w-2xl mx-auto grid" style="grid-template-columns: repeat(<?= max(1, count($nav)) ?>, minmax(0,1fr));">
      <?php foreach ($nav as [$clave, $etiqueta, $archivo]): ?>
        <a href="<?= h($archivo) ?>"
           class="flex flex-col items-center justify-center py-2.5 text-xs <?= $clave === $activa ? 'text-emerald-700 font-semibold' : 'text-slate-500' ?> hover:text-emerald-700">
          <span><?= h($etiqueta) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </nav>

  <footer class="text-center text-xs text-slate-400 py-4">
    Monte Sión · Sistema interno
  </footer>
</body>
</html>
