(() => {
  'use strict';

  const buttonClass = 'pswp__button--astro-rotate';
  const captionToggleClass = 'pswp__button--astro-caption-toggle';
  const captionHiddenClass = 'astro-caption-hidden';
  const rotationAttribute = 'data-astro-rotation';
  const fullscreenRoute = '/index.php/observatory/astronomy-observations';
  const options = window.Joomla?.getOptions?.('astroapiPhoca') || {};
  const statusLabels = {
    idle: 'Bereit',
    uploading: 'Upload läuft',
    waiting: 'Upload wird stabilisiert',
    verifying: 'Dateien werden geprüft',
    importing: 'Import läuft',
    syncing_phoca: 'Phoca-Synchronisation läuft',
    error: 'Importfehler',
    sync_error: 'Phoca-Synchronisationsfehler',
    unavailable: 'AstroAPI nicht erreichbar',
    unknown: 'Status unbekannt',
  };

  const csrfTokenName = () => {
    const tokenFromOptions = window.Joomla?.getOptions?.('csrf.token');
    if (typeof tokenFromOptions === 'string' && tokenFromOptions.length > 0) {
      return tokenFromOptions;
    }

    const scriptOptions = document.querySelector('script.joomla-script-options');
    const raw = scriptOptions?.textContent || '';
    const match = raw.match(/"csrf\.token":"([a-zA-Z0-9]+)"/);
    return match?.[1] || null;
  };

  const prepareSetMainForm = (form) => {
    if (!(form instanceof HTMLFormElement) || !form.classList.contains('astro-set-main-form')) {
      return;
    }

    const currentPath = window.location.pathname + window.location.search + window.location.hash;
    const returnInput = form.querySelector('input[name="return"]');
    if (returnInput instanceof HTMLInputElement) {
      returnInput.value = btoa(currentPath);
    }

    const token = csrfTokenName();
    if (!token) {
      return;
    }

    form.querySelectorAll('input[type="hidden"]').forEach((input) => {
      if (input.name !== token && /^[a-zA-Z0-9]{20,}$/.test(input.name)) {
        input.remove();
      }
    });

    let tokenInput = form.querySelector(`input[name="${token}"]`);
    if (!(tokenInput instanceof HTMLInputElement)) {
      tokenInput = document.createElement('input');
      tokenInput.type = 'hidden';
      tokenInput.name = token;
      form.appendChild(tokenInput);
    }
    tokenInput.value = '1';
  };

  const categoryIdFromUrl = (value) => {
    try {
      const url = new URL(value, window.location.href);
      const segments = url.pathname.split('/').filter(Boolean);
      for (let index = segments.length - 1; index >= 0; index--) {
        const match = segments[index].match(/^(\d+)-/);
        if (match) {
          return match[1];
        }
      }
    } catch {
      return null;
    }

    return null;
  };

  const decorateGallery = () => {
    const gallery = document.querySelector('#phocagallery');
    if (!gallery || !options.homeUrl) {
      return;
    }

    let home = document.querySelector('.astro-phoca-home');
    if (!home) {
      home = document.createElement('a');
      home.className = 'astro-phoca-home';
      home.href = options.homeUrl;
      home.textContent = `← ${options.homeLabel || 'Back to Intranet'}`;
      gallery.prepend(home);
    }

    if (!document.querySelector('.astro-import-status')) {
      const status = document.createElement('section');
      status.className = 'astro-import-status';
      status.setAttribute('aria-live', 'polite');
      status.innerHTML = `
        <span class="astro-import-status__dot"></span>
        <strong>AstroAPI Import</strong>
        <span class="astro-import-status__text">Status wird geladen…</span>
      `;
      gallery.insertBefore(status, home.nextSibling);
    }

    if (!document.querySelector('.astro-classification-filter') && options.classificationTags?.length) {
      const filter = document.createElement('div');
      filter.className = 'astro-classification-filter';
      const label = document.createElement('label');
      label.htmlFor = 'astro-classification-select';
      label.textContent = 'Klassifikation';
      const select = document.createElement('select');
      select.id = 'astro-classification-select';
      select.className = 'form-select';
      select.append(new Option('Alle Beobachtungen', ''));
      options.classificationTags.forEach((tag) => {
        select.append(new Option(
          `${tag.title.replaceAll('_', ' ')} (${tag.count})`,
          String(tag.id)
        ));
      });
      const activeTag = new URL(window.location.href).searchParams.get('tagid');
      if (activeTag) {
        select.value = activeTag;
      }
      select.addEventListener('change', () => {
        const url = new URL(fullscreenRoute, window.location.origin);
        if (select.value) {
          url.pathname += '/category';
          url.searchParams.set('tagid', select.value);
        }
        if (new URL(window.location.href).searchParams.get('tmpl') === 'component') {
          url.searchParams.set('tmpl', 'component');
        }
        window.location.assign(url.pathname + url.search + url.hash);
      });
      filter.append(label, select);
      gallery.insertBefore(filter, document.querySelector('.astro-import-status').nextSibling);
    }

    document.querySelectorAll('form select.form-select').forEach((select) => {
      select.style.setProperty('background-color', '#000', 'important');
      select.style.setProperty('appearance', 'none', 'important');
      select.style.setProperty('color', '#fff', 'important');
      select.style.setProperty('border-color', '#555', 'important');
      select.style.setProperty('color-scheme', 'dark');
      select.querySelectorAll('option').forEach((option) => {
        option.style.setProperty('background-color', '#000', 'important');
        option.style.setProperty('color', '#fff', 'important');
      });
    });

    document.querySelectorAll('.pg-category-box, .pg-item-box').forEach((box) => {
      const title = box.querySelector('.pg-item-box-title.category, .pg-category-box-title');
      const categoryLink = box.querySelector('a[href*="astronomy-observations"]');
      if (!title || !categoryLink) {
        return;
      }

      const categoryId = categoryIdFromUrl(categoryLink.href);
      const count = categoryId && options.categoryCounts
        ? Number(options.categoryCounts[categoryId] ?? 0)
        : 0;
      let badge = title.querySelector('.pg-category-box-count');
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'pg-category-box-count astro-category-total';
        title.appendChild(badge);
      }
      const badgeText = ` (${count})`;
      if (badge.textContent !== badgeText) {
        badge.textContent = badgeText;
      }

      box.querySelectorAll('a[href*="tagid="]').forEach((tagLink) => {
        tagLink.classList.add('astro-tile-classification-hidden');
      });
    });
  };

  const updateImportStatus = async () => {
    const element = document.querySelector('.astro-import-status');
    if (!element || !options.statusUrl) {
      return;
    }

    const text = element.querySelector('.astro-import-status__text');
    try {
      const response = await fetch(options.statusUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const status = await response.json();
      const phase = status.watcher?.phase || 'unavailable';
      const remaining = Number(status.watcher?.remaining_seconds || 0);
      const pending = Number(status.pending_observations || 0);
      const newObservations = Number(status.new_observations || 0);
      const existingObservations = Number(status.existing_observations || 0);
      let detail = statusLabels[phase] || phase;
      if (phase === 'waiting' && remaining > 0) {
        detail += ` (${Math.ceil(remaining)} s)`;
      }
      if (status.watcher?.files > 0) {
        detail += ` · ${status.watcher.files} Datei(en)`;
      }
      if (pending > 0) {
        detail += ` · ${pending} Beobachtung(en) bereit`;
      }
      if (['importing', 'syncing_phoca'].includes(phase)) {
        detail += ` · ${newObservations} neu, ${existingObservations} bestehend`;
      }
      element.dataset.phase = phase;
      text.textContent = detail;
    } catch {
      element.dataset.phase = 'unavailable';
      text.textContent = statusLabels.unavailable;
    }
  };

  const preserveFullscreenNavigation = () => {
    if (new URL(window.location.href).searchParams.get('tmpl') !== 'component') {
      return;
    }

    document.querySelectorAll('a[href]').forEach((link) => {
      const href = link.getAttribute('href');
      if (!href || href.startsWith('#') || href.startsWith('javascript:')) {
        return;
      }

      const url = new URL(href, window.location.href);
      if (
        url.origin === window.location.origin
        && (url.pathname === fullscreenRoute || url.pathname.startsWith(`${fullscreenRoute}/`))
        && url.searchParams.get('format') !== 'feed'
        && url.searchParams.get('tmpl') !== 'component'
      ) {
        url.searchParams.set('tmpl', 'component');
        link.setAttribute('href', `${url.pathname}${url.search}${url.hash}`);
      }
    });
  };

  const activeImage = () => {
    const viewportCenter = window.innerWidth / 2;
    const candidates = Array.from(document.querySelectorAll('.pswp__img'));

    return candidates
      .filter((image) => image.complete && image.naturalWidth > 0)
      .map((image) => {
        const rect = image.getBoundingClientRect();
        return { image, distance: Math.abs(rect.left + rect.width / 2 - viewportCenter), rect };
      })
      .filter(({ rect }) => rect.width > 0 && rect.right > 0 && rect.left < window.innerWidth)
      .sort((left, right) => left.distance - right.distance)[0]?.image;
  };

  const applyRotation = (direction) => {
    const image = activeImage();
    if (!image) {
      return;
    }

    const current = Number(image.getAttribute(rotationAttribute) || 0);
    const angle = (current + direction * 90 + 360) % 360;
    image.setAttribute(rotationAttribute, String(angle));
    fitRotatedImage(image, angle);
  };

  const fitRotatedImage = (image, angle) => {
    const width = parseFloat(image.style.width) || image.naturalWidth;
    const height = parseFloat(image.style.height) || image.naturalHeight;
    const photoswipe = image.closest('.pswp');
    const photoswipeRect = photoswipe?.getBoundingClientRect();
    const topBarHeight = photoswipe?.querySelector('.pswp__top-bar')?.getBoundingClientRect().height || 0;
    const captionHeight = photoswipe?.querySelector('.pswp__caption')?.getBoundingClientRect().height || 0;
    const availableWidth = Math.max(1, (photoswipeRect?.width || window.innerWidth) - 40);
    const availableHeight = Math.max(
      1,
      (photoswipeRect?.height || window.innerHeight) - topBarHeight - captionHeight - 20
    );
    const fit = angle % 180 === 0
      ? Math.min(1, availableWidth / width, availableHeight / height)
      : Math.min(
          1,
          availableWidth / height,
          availableHeight / width
        );

    image.style.rotate = `${angle}deg`;
    image.style.scale = String(Math.max(0.1, fit));
  };

  const refitActiveImage = () => {
    const image = activeImage();
    if (!image) {
      return;
    }

    const angle = Number(image.getAttribute(rotationAttribute) || 0);
    if (angle !== 0) {
      fitRotatedImage(image, angle);
    }
  };

  const addButton = (container, direction, label) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `pswp__button ${buttonClass} pswp__button--astro-rotate-${direction < 0 ? 'left' : 'right'}`;
    button.setAttribute('aria-label', label);
    button.title = label;
    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      applyRotation(direction);
    });
    container.appendChild(button);
  };

  const updateCaptionToggleButton = () => {
    const photoswipe = document.querySelector('.pswp');
    const button = document.querySelector(`.${captionToggleClass}`);
    if (!button) {
      return;
    }

    const isHidden = photoswipe?.classList.contains(captionHiddenClass) || false;
    const title = isHidden ? 'Show details overlay' : 'Hide details overlay';
    button.setAttribute('aria-label', title);
    button.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
    button.title = title;
  };

  const toggleCaptionOverlay = () => {
    const photoswipe = document.querySelector('.pswp');
    if (!photoswipe) {
      return;
    }

    photoswipe.classList.toggle(captionHiddenClass);
    updateCaptionToggleButton();
    refitActiveImage();
  };

  const installControls = () => {
    const topBar = document.querySelector('.pswp__top-bar');
    if (!topBar || topBar.querySelector(`.${buttonClass}`) || topBar.querySelector(`.${captionToggleClass}`)) {
      return;
    }
    const photoswipe = topBar.closest('.pswp');
    photoswipe?.classList.add(captionHiddenClass);

    addButton(topBar, -1, 'Rotate 90 degrees left');
    addButton(topBar, 1, 'Rotate 90 degrees right');

    const captionButton = document.createElement('button');
    captionButton.type = 'button';
    captionButton.className = `pswp__button ${captionToggleClass}`;
    captionButton.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      toggleCaptionOverlay();
    });
    topBar.appendChild(captionButton);
    updateCaptionToggleButton();
  };

  const resetInactiveSlides = () => {
    const active = activeImage();
    document.querySelectorAll(`.pswp__img[${rotationAttribute}]`).forEach((image) => {
      if (image !== active) {
        image.removeAttribute(rotationAttribute);
        image.style.removeProperty('rotate');
        image.style.removeProperty('scale');
      }
    });
  };

  const observer = new MutationObserver(() => {
    preserveFullscreenNavigation();
    decorateGallery();
    installControls();
    updateCaptionToggleButton();
    document.querySelectorAll('form.astro-set-main-form').forEach((form) => prepareSetMainForm(form));
  });

  observer.observe(document.documentElement, {
    childList: true,
    subtree: true,
  });

  document.addEventListener('click', (event) => {
    if (event.target.closest('.astro-observation-video')) {
      event.stopPropagation();
      return;
    }

    if (event.target.closest('.pswp__button--arrow--left, .pswp__button--arrow--right')) {
      window.requestAnimationFrame(resetInactiveSlides);
    }
  });

  document.addEventListener('pointerdown', (event) => {
    if (event.target.closest('.astro-observation-video')) {
      event.stopPropagation();
    }
  }, true);

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.classList.contains('astro-set-main-form')) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    prepareSetMainForm(form);

    const button = form.querySelector('button[type="submit"]');
    if (button instanceof HTMLButtonElement) {
      button.disabled = true;
      button.textContent = 'Setting...';
    }

    fetch(form.action, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      body: new FormData(form),
    })
      .then(async (response) => {
        let result;
        try {
          result = await response.json();
        } catch {
          throw new Error(`Main image request failed (${response.status})`);
        }
        if (!response.ok || !result.success) {
          throw new Error(result.message || `Main image request failed (${response.status})`);
        }
        return result;
      })
      .then((result) => {
        const relative = String(result.relative || form.dataset.relative || '');
        if (relative) {
          const url = `/images/phocagallery/${relative.replace(/^\/+/, '')}?v=${Date.now()}`;
          document.querySelectorAll('.pswp__img').forEach((image) => {
            if (image instanceof HTMLImageElement) {
              image.src = url;
              image.removeAttribute('srcset');
            }
          });
        }

        const listItem = form.closest('li');
        form.remove();
        if (listItem) {
          listItem.parentElement?.querySelectorAll('.astro-main-image-pill').forEach((oldMain) => oldMain.remove());
          const pill = document.createElement('span');
          pill.className = 'astro-main-image-pill';
          pill.textContent = 'main';
          listItem.appendChild(pill);
        }

        window.dispatchEvent(new CustomEvent('astroapi-main-image-updated', { detail: result }));
      })
      .catch((error) => {
        window.alert(error.message);
        if (button instanceof HTMLButtonElement) {
          button.disabled = false;
          button.textContent = 'Set as main';
        }
      });
  }, true);

  window.addEventListener('resize', refitActiveImage);
  preserveFullscreenNavigation();
  decorateGallery();
  document.querySelectorAll('form.astro-set-main-form').forEach((form) => prepareSetMainForm(form));
  updateImportStatus();
  window.setInterval(updateImportStatus, 5000);
})();
