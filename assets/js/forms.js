/* forms.js — Comportamiento estándar de formularios
   · Auto-foco en el primer campo editable al cargar
   · Seleccionar todo al recibir foco (para reemplazar fácilmente)
   · Enter = Tab (avanza al siguiente campo)
   · En el último campo dispara el evento personalizado 'formUltimoCampo'
     (cancelable) antes de mover el foco al botón submit
*/
(function () {
  'use strict';

  const SEL_CAMPO = [
    'input:not([type=hidden]):not([readonly]):not([disabled])',
    'select:not([disabled])',
    'textarea:not([readonly]):not([disabled])'
  ].join(',');

  // Tipos de <input> que NO tienen selección de texto con .select()
  const SIN_SELECT = new Set([
    'checkbox', 'radio', 'file', 'color',
    'date', 'datetime-local', 'time', 'month', 'week', 'range'
  ]);

  function camposVisibles(form) {
    return Array.from(form.querySelectorAll(SEL_CAMPO))
      .filter(el => el.offsetParent !== null);
  }

  /* ── Auto-foco al cargar ── */
  document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form');
    if (!form) return;
    const campos = camposVisibles(form);
    if (campos.length) campos[0].focus();
  });

  /* ── Seleccionar todo al recibir foco ── */
  document.addEventListener('focusin', e => {
    const el = e.target;
    if (!el?.tagName) return;

    if (el.tagName === 'TEXTAREA') {
      setTimeout(() => el.select(), 0);
      return;
    }
    if (el.tagName === 'INPUT' && !SIN_SELECT.has((el.type || '').toLowerCase())) {
      setTimeout(() => el.select(), 0);
    }
  });

  /* ── Enter = Tab ── */
  document.addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;

    const el = e.target;
    if (!el?.tagName) return;

    // Textarea: Enter es salto de línea normal
    if (el.tagName === 'TEXTAREA') return;

    // Botones: Enter los activa normalmente
    const tipo = (el.type || '').toLowerCase();
    if (el.tagName === 'BUTTON' || tipo === 'submit' || tipo === 'button' || tipo === 'reset') return;

    const form = el.closest('form');
    if (!form) return;

    e.preventDefault();

    const campos = camposVisibles(form);
    const idx = campos.indexOf(el);
    if (idx === -1) return;

    if (idx < campos.length - 1) {
      campos[idx + 1].focus();
    } else {
      // Último campo visible: notificar a la página
      // Si el handler llama preventDefault(), no hacemos nada más
      const continuar = el.dispatchEvent(
        new CustomEvent('formUltimoCampo', { bubbles: true, cancelable: true })
      );
      if (continuar) {
        // Sin handler específico: mover al botón submit
        const submit = form.querySelector('[type=submit]');
        if (submit) submit.focus();
      }
    }
  });

})();
