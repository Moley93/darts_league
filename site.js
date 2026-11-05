/* Crawley Darts League: site.js (version 1, November 2026)
   Header and menu behaviour for pages on the new page shell. Nothing else:
   page scripts keep their own code and do not depend on this file.

   Markup it expects (the same on every page):
     header.site-header > .site-header__bar > button.menu-toggle[aria-controls="site-nav"]
     nav#site-nav.site-nav  li.site-nav__group > button.site-nav__toggle + ul.site-nav__sub

   - Below 64em the Menu button opens a full-height panel (.is-open on the
     header) and groups work as accordions; the group holding the current
     page starts open. Moving keyboard focus out of the header closes the
     panel, so focus never lands on the page hidden behind it.
   - From 64em groups open a dropdown on click or tap (never hover only).
     One is open at a time; a second click, a click or tap outside, Escape
     or tabbing out closes it. An open dropdown is kept inside the screen.
   - Escape closes the open dropdown or the phone menu and returns focus to
     the button that opened it.
   - Leaving the page closes everything, so the Back button never brings
     the page back with the menu still open.
   - Marks the link to the current page with aria-current="page" and its
     group button with .is-current.
   Safe to load with or without defer, and twice. Written for older
   browsers too (ES2017: no ?. or ??). */
(function () {
  'use strict';

  function init() {
    var header = document.querySelector('.site-header');
    if (!header || header.getAttribute('data-ready') === 'true') return;
    var nav = header.querySelector('.site-nav');
    if (!nav) return;
    header.setAttribute('data-ready', 'true');

    var bar = header.querySelector('.site-header__bar');
    var menuButton = header.querySelector('.menu-toggle');
    var toggles = Array.prototype.slice.call(nav.querySelectorAll('.site-nav__toggle'));
    var wide = window.matchMedia ? window.matchMedia('(min-width: 64em)') : { matches: true };
    var currentToggle = null;

    function isWide() { return !!wide.matches; }

    function subFor(toggle) {
      var next = toggle.nextElementSibling;
      return next && next.classList.contains('site-nav__sub') ? next : null;
    }

    // From 64em an open dropdown must stay on screen, also when the menu bar
    // wraps onto a second row (wide letter spacing, very large text).
    function keepOnScreen(sub) {
      var r = sub.getBoundingClientRect();
      var edge = document.documentElement.clientWidth;
      if (r.right > edge) { sub.style.left = 'auto'; sub.style.right = '0'; }
      else if (r.left < 0) { sub.style.left = '0'; sub.style.right = 'auto'; }
    }

    function setGroup(toggle, open) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      var sub = subFor(toggle);
      if (!sub) return;
      sub.style.left = '';
      sub.style.right = '';
      if (open && isWide()) keepOnScreen(sub);
    }

    function closeGroups(except) {
      toggles.forEach(function (t) { if (t !== except) setGroup(t, false); });
    }

    function openToggle() {
      for (var i = 0; i < toggles.length; i++) {
        if (toggles[i].getAttribute('aria-expanded') === 'true') return toggles[i];
      }
      return null;
    }

    function menuIsOpen() { return header.classList.contains('is-open'); }

    // The panel starts right under the bar, however tall large text makes it
    // (never above the screen: on a short screen the bar scrolls away)
    function placePanel() {
      nav.style.top = menuIsOpen() && bar && !isWide() ? Math.max(0, bar.getBoundingClientRect().bottom) + 'px' : '';
    }

    function setMenu(open) {
      if (!menuButton) return;
      if (open) header.classList.add('is-open'); else header.classList.remove('is-open');
      menuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
      placePanel();
      if (open) {
        // Start with only the group of the current page open
        closeGroups(currentToggle);
        if (currentToggle) setGroup(currentToggle, true);
      }
    }

    // 1. Current page ------------------------------------------------------
    var file = (location.pathname.split('/').pop() || 'index.html').toLowerCase();
    try { file = decodeURIComponent(file); } catch (e) { /* keep as is */ }
    Array.prototype.forEach.call(nav.querySelectorAll('a[href]'), function (a) {
      var target = (a.getAttribute('href') || '').split('#')[0].split('?')[0].toLowerCase();
      if (target !== file) return;
      a.setAttribute('aria-current', 'page');
      var group = a.closest('.site-nav__group');
      var toggle = group ? group.querySelector('.site-nav__toggle') : null;
      if (toggle) {
        toggle.classList.add('is-current');
        currentToggle = toggle;
      }
    });

    // 2. Menu button (phones and tablets) ------------------------------------
    if (menuButton) {
      menuButton.addEventListener('click', function () {
        setMenu(!menuIsOpen());
      });
    }
    // Tabbing (or Shift+Tabbing) out of the header closes the panel
    header.addEventListener('focusout', function (e) {
      if (isWide() || !menuIsOpen() || !e.relatedTarget) return;
      if (!header.contains(e.relatedTarget)) setMenu(false);
    });

    // 3. Group buttons: dropdowns from 64em, accordions below ----------------
    toggles.forEach(function (toggle) {
      if (!subFor(toggle)) return;
      toggle.addEventListener('click', function () {
        var open = toggle.getAttribute('aria-expanded') !== 'true';
        closeGroups(toggle);
        setGroup(toggle, open);
      });
      // Desktop: tabbing out of a group closes it. Only when focus moves to
      // another element: a mouse click elsewhere is handled below.
      toggle.parentNode.addEventListener('focusout', function (e) {
        if (!isWide() || !e.relatedTarget) return;
        if (!toggle.parentNode.contains(e.relatedTarget)) setGroup(toggle, false);
      });
    });

    // 4. Click or tap outside: closes a desktop dropdown or the phone menu ---
    document.addEventListener('click', function (e) {
      if (isWide()) {
        var open = openToggle();
        if (open && !open.parentNode.contains(e.target)) setGroup(open, false);
      } else if (menuIsOpen() && !header.contains(e.target)) {
        setMenu(false);
      }
    });
    // iPads get the desktop dropdowns, and Safari on iPad sends no click for
    // a tap on plain text, so a touch outside closes the dropdown too.
    document.addEventListener('touchstart', function (e) {
      if (!isWide()) return;
      var open = openToggle();
      if (open && !open.parentNode.contains(e.target)) setGroup(open, false);
    }, { passive: true });

    // 5. Escape --------------------------------------------------------------
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' && e.key !== 'Esc') return;
      if (isWide()) {
        var open = openToggle();
        if (!open) return;
        setGroup(open, false);
        open.focus();
      } else if (menuIsOpen()) {
        setMenu(false);
        menuButton.focus();
      }
    });

    // 6. Switching between the phone and desktop layouts, or leaving the
    //    page (Back restores it from the cache), resets everything ---------
    function reset() {
      setMenu(false);
      closeGroups(null);
    }
    if (wide.addEventListener) wide.addEventListener('change', reset);
    else if (wide.addListener) wide.addListener(reset);
    window.addEventListener('pagehide', reset);
    window.addEventListener('resize', placePanel);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
