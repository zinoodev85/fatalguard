/**
 * Debug Logs overlay. Toggled from the admin-bar "Logs" item. All data over
 * REST via wp.apiFetch (sends cookies + X-WP-Nonce). Every value from the
 * server is rendered with textContent — never innerHTML — so a log line that
 * contains markup can't execute.
 *
 * UI state (open / lowered / active tab) is persisted in localStorage so the
 * overlay survives a page navigation. On a fresh page load it reopens in the
 * LOWERED state (a thin header bar) so it never covers content unprompted; the
 * user raises it (or the admin-bar item opens it full).
 */
(function () {
  var apiFetch = window.wp && window.wp.apiFetch
  var classify = window.fatalguardClassify || {severity: function () { return '' }, severityForType: function () { return 'error' }}
  var NS = '/fatalguard/v1/debug-logs'
  var STORE_KEY = 'fatalguard-ui'

  var overlay, tabsEl, bodyEl, headEl, filtersEl, footEl
  var sources = []
  var status = null
  var fileLogging = null // {enabled, writable} from /sources — drives the foot checkbox
  var current = null // {type:'log'|'fatals'|'status', id}
  var minimized = false
  var liveTimer = null
  var peekFadeTimer = null
  var inFlight = false
  var tags = []
  var selectedTag = null  // filter by tag
  var searchQuery = ''    // free-text search over log lines
  var grouped = false     // collapse repeats into distinct errors (count + last-seen)
  var searchTimer = null
  var serverClockOffset = 0  // B164: (res.now - Date.now()/1000) to correct skewed clocks
  var fatalsOffset = 0       // B163: offset for "Load more" pagination
  var FATALS_PAGE = 100      // records per page
  var lastFocus = null       // B199: element that had focus before overlay opened
  // Externally-registered tabs (e.g. the FatalGuard "Alerts" connect panel). Each
  // is {id, label, render(container)}; render owns its subtree + its own fetching.
  // Empty unless a companion script calls window.fatalguardDebugLogs.registerTab(...),
  // so the internal sites see no extra tabs.
  var extraTabs = []

  function el(tag, cls, text) {
    var n = document.createElement(tag)
    if (cls) n.className = cls
    if (text != null) n.textContent = text
    return n
  }

  function ready() {
    overlay = document.getElementById('fatalguard-overlay')
    if (!overlay) return false
    tabsEl = document.getElementById('fatalguard-tabs')
    bodyEl = document.getElementById('fatalguard-body')
    headEl = document.getElementById('fatalguard-head')
    filtersEl = document.getElementById('fatalguard-filters')
    footEl = document.getElementById('fatalguard-foot')
    return true
  }

  // --- persistence ---------------------------------------------------------
  function persist() {
    try {
      localStorage.setItem(STORE_KEY, JSON.stringify({
        open: !!(overlay && !overlay.hidden),
        min: minimized,
        tab: current,
      }))
    } catch (e) {}
  }
  function restore() {
    try { return JSON.parse(localStorage.getItem(STORE_KEY) || 'null') } catch (e) { return null }
  }

  function api(path, opts) {
    opts = opts || {}
    opts.path = NS + path
    return apiFetch(opts)
  }

  // --- open / lower / close ------------------------------------------------
  function setMin(on) {
    minimized = !!on
    if (!overlay) return
    overlay.classList.toggle('is-min', minimized)
    var btn = headEl && headEl.querySelector('button[data-act="min"]')
    if (btn) {
      btn.setAttribute('aria-pressed', minimized ? 'true' : 'false')
      btn.textContent = minimized ? '⌃' : '⌄'
      btn.setAttribute('aria-label', minimized ? wp.i18n.__( 'Raise', 'fatalguard' ) : wp.i18n.__( 'Lower', 'fatalguard' ))
      btn.title = minimized ? wp.i18n.__( 'Raise (expand)', 'fatalguard' ) : wp.i18n.__( 'Lower (collapse to a bar)', 'fatalguard' )
    }
  }

  function open(opts) {
    opts = opts || {}
    if (!ready()) return
    // B199: capture trigger so close() can return focus
    if (document.activeElement && document.activeElement !== document.body) {
      lastFocus = document.activeElement
    }
    overlay.hidden = false
    overlay.setAttribute('aria-hidden', 'false')
    // B217: re-enable the peek live region when visible
    var peek = document.getElementById('fatalguard-peek')
    if (peek) peek.setAttribute('aria-live', 'polite')
    setMin(!!opts.min)
    persist()
    loadSources()
  }
  function close() {
    if (!ready()) return
    overlay.hidden = true
    overlay.setAttribute('aria-hidden', 'true')
    var peek = document.getElementById('fatalguard-peek') // B217: silence live region
    if (peek) peek.setAttribute('aria-live', 'off')
    stopLive()
    stopFade()
    persist()
    if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus() // B199
  }
  // The admin-bar item: open full when hidden, raise when lowered, else close.
  function toggle() {
    if (!ready()) return
    if (overlay.hidden) return open({min: false})
    if (minimized) { setMin(false); persist(); selectCurrent(); return }
    close()
  }

  function loadSources() {
    setBody(el('div', 'fatalguard-loading', wp.i18n.__( 'Loading…', 'fatalguard' )))
    api('/sources')
      .then(function (res) {
        sources = (res && res.sources) || []
        status = (res && res.status) || null
        fileLogging = (res && res.file_logging) || null
        renderLogToggle()
        buildTabs()
        if (!currentValid()) {
          var firstFile = sources.filter(function (s) { return s.kind === 'file' })[0]
          current = firstFile ? {type: 'log', id: firstFile.id} : {type: 'status'}
        }
        selectCurrent()
      })
      .catch(function (e) { renderError(e) })
  }

  // A restored tab may point at a log that no longer exists — fall back then.
  function currentValid() {
    if (!current) return false
    if (current.type === 'status' || current.type === 'fatals') return true
    if (current.type === 'log') return sources.some(function (s) { return s.id === current.id })
    if (current.type === 'ext') return extraTabs.some(function (t) { return t.id === current.id })
    return false
  }

  function buildTabs() {
    tabsEl.textContent = ''
    sources.forEach(function (s) {
      tabsEl.appendChild(makeTab(s.label + (s.kind === 'stream' ? ' (stream)' : ''), {type: 'log', id: s.id}))
    })
    tabsEl.appendChild(makeTab(wp.i18n.__( 'Fatals', 'fatalguard' ), {type: 'fatals'}))
    tabsEl.appendChild(makeTab(wp.i18n.__( 'Status', 'fatalguard' ), {type: 'status'}))
    extraTabs.forEach(function (t) {
      tabsEl.appendChild(makeTab(t.label, {type: 'ext', id: t.id}))
    })
  }

  function sameTarget(a, b) {
    return !!a && !!b && a.type === b.type && a.id === b.id
  }

  function makeTab(label, target) {
    var b = el('button', 'fatalguard-tab', label)
    b.type = 'button'
    b.setAttribute('role', 'tab')
    // B188/B216: initialise aria-selected and roving tabindex
    var isActive = sameTarget(current, target)
    b.setAttribute('aria-selected', isActive ? 'true' : 'false')
    b.setAttribute('tabindex', isActive ? '0' : '-1')
    b._target = target
    b.addEventListener('click', function () {
      current = target
      if (minimized) setMin(false) // picking a tab raises the lowered overlay
      selectCurrent()
    })
    if (isActive) b.classList.add('is-active')
    return b
  }

  function selectCurrent() {
    // B188/B194/B216: update aria-selected + roving tabindex on all tabs
    var activeIdx = tabIndexFor(current)
    Array.prototype.forEach.call(tabsEl.children, function (b, i) {
      b.classList.remove('is-active')
      b.setAttribute('aria-selected', 'false')
      b.setAttribute('tabindex', '-1')
      if (i === activeIdx) {
        b.classList.add('is-active')
        b.setAttribute('aria-selected', 'true')
        b.setAttribute('tabindex', '0')
      }
    })
    // B195: link tabpanel to its controlling tab
    if (bodyEl) {
      var activeBtn = (activeIdx >= 0 && tabsEl.children[activeIdx]) ? tabsEl.children[activeIdx] : null
      if (activeBtn) {
        if (!activeBtn.id) activeBtn.id = 'fatalguard-tab-' + activeIdx
        bodyEl.setAttribute('aria-labelledby', activeBtn.id)
      }
    }
    persist()
    if (minimized) return loadPeek() // collapsed — show only the last line in the bar
    setPeek('')
    renderControls()
    if (current.type === 'ext') return renderExt()
    if (current.type === 'status') return renderStatus()
    if (current.type === 'fatals') return loadFatals()
    loadTags()
    return loadTail()
  }

  // Hand the body to an externally-registered tab. It owns its subtree + fetching.
  function renderExt() {
    var t = extraTabs.filter(function (x) { return x.id === current.id })[0]
    var container = el('div', 'fatalguard-ext')
    setBody(container)
    if (!t) return
    try { t.render(container) } catch (e) { container.textContent = 'Error: ' + (e && e.message) }
  }

  // The collapsed bar shows the most recent line of the active source in its
  // free space, so a glance tells you what just happened without expanding.
  function loadPeek() {
    setPeek('…', '')  // loading indicator while fetching
    if (!current) return setPeek('', '')
    if (current.type === 'status') {
      return setPeek(status ? ('Env: ' + (status.environment || '?')) : wp.i18n.__( 'Status', 'fatalguard' ), '')
    }
    if (current.type === 'ext') {
      var xt = extraTabs.filter(function (x) { return x.id === current.id })[0]
      return setPeek(xt ? xt.label : '', '')
    }
    if (current.type === 'fatals') {
      api('/fatals?max=1')
        .then(function (res) {
          var f = res && res.fatals && res.fatals[0]
          if (!f) return setPeek(wp.i18n.__( '(no fatals)', 'fatalguard' ), '')
          var msg = (f.message || '').substring(0, 100)  // truncate for header
          setPeek(msg, classify.severityForType(f.type))
          var t = Date.parse(f.time)  // gmdate('c') ISO8601
          if (!isNaN(t) && typeof res.now === 'number') startFade(res.now * 1000 - t)
        })
        .catch(function () { setPeek(wp.i18n.__( '(error)', 'fatalguard' ), '') })
      return
    }
    // WordPress/PHP log
    if (current.type === 'log') {
      api('/tail?source=' + encodeURIComponent(current.id) + '&max=1')
        .then(function (res) {
          var lines = (res && res.lines) || []
          var last = lines.length ? lines[lines.length - 1] : ''
          if (!last && res && res.note) last = res.note
          if (!last) return setPeek(wp.i18n.__( '(no entries)', 'fatalguard' ), '')
          var truncated = last.substring(0, 100)
          setPeek(truncated, classify.severity(last))
          if (typeof res.now === 'number' && typeof res.mtime === 'number' && res.mtime > 0) {
            startFade((res.now - res.mtime) * 1000)
          }
        })
        .catch(function () { setPeek(wp.i18n.__( '(error)', 'fatalguard' ), '') })
    }
  }

  // Fade the collapsed peek by the entry's age: 10%/second, floor at 30% by ~7s.
  // Anchored to a monotonic clock so it keeps ticking smoothly between refreshes.
  function startFade(baseAgeMs) {
    stopFade()
    if (!minimized) return
    var anchor = nowMs()
    var base = Math.max(0, baseAgeMs || 0)
    function tick() {
      var p = document.getElementById('fatalguard-peek')
      if (!p || !minimized) return stopFade()
      var op = 1 - 0.1 * ((base + (nowMs() - anchor)) / 1000)
      if (op < 0.3) op = 0.3  // floor at 30% opacity
      if (op > 1) op = 1
      p.style.opacity = String(op)
    }
    tick()
    peekFadeTimer = setInterval(tick, 200)
  }
  function stopFade() {
    if (peekFadeTimer) { clearInterval(peekFadeTimer); peekFadeTimer = null }
  }
  function nowMs() {
    return (window.performance && performance.now) ? performance.now() : Date.now()
  }

  function setPeek(text, sev) {
    stopFade()
    var p = document.getElementById('fatalguard-peek')
    if (!p) return
    p.textContent = text || ''
    p.title = text || ''
    p.className = 'fatalguard-spacer' + (text && sev ? ' sev-' + sev : '')
    p.style.opacity = '' // CSS decides (1 lowered / 0 expanded) until a fade overrides
  }

  // Match by the target stashed on each tab button — robust to how many tabs
  // exist (extra registered tabs sit after Status, so positional math breaks).
  function tabIndexFor(target) {
    for (var i = 0; i < tabsEl.children.length; i++) {
      if (sameTarget(tabsEl.children[i]._target, target)) return i
    }
    return -1
  }

  function loadTail() {
    if (inFlight) return
    inFlight = true
    var q = searchQuery ? '&q=' + encodeURIComponent(searchQuery) : ''
    var g = grouped ? '&group=1' : ''
    api('/tail?source=' + encodeURIComponent(current.id) + '&max=300' + q + g)
      .then(function (res) {
        // B164: capture server clock offset
        if (res && typeof res.now === 'number') {
          serverClockOffset = res.now - Math.floor(Date.now() / 1000)
        }
        grouped ? renderGroupedLines(res) : renderLines(res)
      })
      .catch(function (e) { renderError(e) })
      .finally(function () { inFlight = false })
  }

  function loadFatals(append) {
    // B163: paginate — load FATALS_PAGE records at a time with offset
    var off = append ? fatalsOffset : 0
    if (!append) fatalsOffset = 0
    api('/fatals?max=' + FATALS_PAGE + '&offset=' + off + (grouped ? '&group=1' : ''))
      .then(function (res) {
        // B164: capture server clock offset for skew-free age display
        if (res && typeof res.now === 'number') {
          serverClockOffset = res.now - Math.floor(Date.now() / 1000)
        }
        var items = grouped ? ((res && res.grouped) || []) : ((res && res.fatals) || [])
        if (append) {
          renderFatalsAppend(items, res)
        } else {
          renderFatals(items, res)
        }
        fatalsOffset = off + items.length
      })
      .catch(function (e) { renderError(e) })
  }

  function loadTags() {
    if (!current || current.type !== 'log') return
    api('/tags?source=' + encodeURIComponent(current.id) + '&max=500')
      .then(function (res) {
        tags = (res && res.tags) || []
        renderControls()
      })
      .catch(function () { tags = []; renderControls() })
  }

  // Order (left→right): Search, Tag filter, Group repeats. Rendered per tab
  // select; search/group handlers reload only the body so the input keeps focus
  // while typing.
  function renderControls() {
    if (!filtersEl || !current) return
    filtersEl.textContent = ''

    // 1) Search (log tabs only).
    if (current.type === 'log') {
      var search = document.createElement('input')
      search.type = 'search'
      search.className = 'fatalguard-search'
      search.placeholder = 'Search log…'
      search.setAttribute('aria-label', 'Search log')  // B192: accessible label
      search.value = searchQuery
      search.addEventListener('input', function (e) {
        searchQuery = e.target.value
        clearTimeout(searchTimer)
        searchTimer = setTimeout(loadTail, 250)
      })
      filtersEl.appendChild(search)
    }

    // 2) Tag filter (log tabs with detected tags).
    if (current.type === 'log' && tags.length > 0) {
      var label = el('label', 'fatalguard-filter-label', 'Tag: ')
      var select = document.createElement('select')
      select.className = 'fatalguard-filter-select'
      select.value = selectedTag || ''
      select.addEventListener('change', function (e) {
        selectedTag = e.target.value || null
        selectCurrent()  // re-render with new filter
      })
      var opt = document.createElement('option')
      opt.value = ''
      opt.textContent = '(all)'
      select.appendChild(opt)
      tags.forEach(function (tag) {
        var o = document.createElement('option')
        o.value = tag
        o.textContent = '[' + tag + ']'
        if (tag === selectedTag) o.selected = true
        select.appendChild(o)
      })
      label.appendChild(select)
      filtersEl.appendChild(label)
    }

    // 3) Group repeats (log + fatals).
    if (current.type !== 'status' && current.type !== 'ext') {
      var gl = el('label', 'fatalguard-group')
      var gc = document.createElement('input')
      gc.type = 'checkbox'
      gc.className = 'fatalguard-group-cb'
      gc.checked = grouped
      gc.addEventListener('change', function (e) {
        grouped = e.target.checked
        current.type === 'fatals' ? loadFatals() : loadTail()
      })
      gl.appendChild(gc)
      gl.appendChild(document.createTextNode(' Group repeats'))
      filtersEl.appendChild(gl)
    }
  }

  // "Log PHP errors" checkbox (one builder, used in the bottom bar and in the
  // empty-log CTA). State is the live WP_DEBUG_LOG constant reported by /sources
  // (reality, not a cached option); toggling POSTs /file-logging, which
  // writes/restores wp-config.php.
  function makeLogCheckbox() {
    var label = el('label', 'fatalguard-group fatalguard-logcb')
    var cb = document.createElement('input')
    cb.type = 'checkbox'
    cb.className = 'fatalguard-group-cb'
    cb.checked = !!(fileLogging && fileLogging.enabled)
    if (!fileLogging.writable) {
      cb.disabled = true
      label.title = wp.i18n.__( 'wp-config.php is not writable — see the settings page for the manual snippet.', 'fatalguard' )
    } else {
      label.title = wp.i18n.__( 'Route PHP warnings and notices into the protected log. Updates wp-config.php; turning it off restores your original settings.', 'fatalguard' )
    }
    cb.addEventListener('change', function () {
      var on = cb.checked
      cb.disabled = true
      api('/file-logging', {method: 'POST', data: {enabled: on}})
        .then(function () {
          fileLogging.enabled = on
          flash(on ? wp.i18n.__( 'PHP error logging on.', 'fatalguard' ) : wp.i18n.__( 'PHP error logging off — original wp-config restored.', 'fatalguard' ))
          renderLogToggle()                                    // keep the foot copy in step
          if (current && current.type === 'log') loadTail()    // CTA body ↔ log lines
        })
        .catch(function (e2) {
          cb.checked = !on
          flash(wp.i18n.__( 'Could not update wp-config.php: ', 'fatalguard' ) + e2.message)
        })
        .finally(function () { cb.disabled = false })
    })
    label.appendChild(cb)
    label.appendChild(document.createTextNode(' ' + wp.i18n.__( 'Log PHP errors', 'fatalguard' )))
    return label
  }

  /** The bottom-bar copy of the checkbox (left of Live/Refresh/Clear). */
  function renderLogToggle() {
    if (!footEl) return
    var old = document.getElementById('fatalguard-logcb-label')
    if (old) old.remove()
    if (!fileLogging) return
    var label = makeLogCheckbox()
    label.id = 'fatalguard-logcb-label'
    var liveBtn = footEl.querySelector('button[data-act="live"]')
    footEl.insertBefore(label, liveBtn || null)
  }

  // Empty-state node for a log tab. When file logging is off (and the emptiness
  // isn't caused by a search/tag filter), the body becomes the call-to-action:
  // a centered checkbox that turns logging on right there.
  function emptyLogNode(msg) {
    var src = current && current.type === 'log' ? sources.filter(function (x) { return x.id === current.id })[0] : null
    var isStream = !!(src && src.kind === 'stream') // enabling logging can't fill a stream tab
    if (fileLogging && !fileLogging.enabled && current && current.type === 'log' && !isStream && !searchQuery && !selectedTag) {
      var wrap = el('div', 'fatalguard-empty fatalguard-cta')
      wrap.appendChild(el('div', 'fatalguard-cta-msg', wp.i18n.__( 'PHP error logging is off — nothing is being written here.', 'fatalguard' )))
      wrap.appendChild(makeLogCheckbox())
      return wrap
    }
    return el('div', 'fatalguard-empty', msg)
  }

  function matchesFilter(line) {
    if (!selectedTag) return true
    return line.indexOf('[' + selectedTag + ']') !== -1
  }

  function fmtAge(epoch) {
    if (!epoch) return ''
    // B164: use server-clock offset to correct skewed client clocks
    var serverNow = Math.floor(Date.now() / 1000) + serverClockOffset
    var s = Math.max(0, serverNow - epoch)
    if (s < 60) return s + 's ago'
    if (s < 3600) return Math.floor(s / 60) + 'm ago'
    if (s < 86400) return Math.floor(s / 3600) + 'h ago'
    return Math.floor(s / 86400) + 'd ago'
  }

  function renderGroupedLines(res) {
    var groups = (res && res.grouped) || []
    if (!groups.length) {
      return setBody(emptyLogNode(searchQuery ? 'No entries match “' + searchQuery + '”.' : 'No entries yet.'))
    }
    var wrap = el('div', 'fatalguard-lines')
    groups.forEach(function (g) {
      var row = el('div', 'fatalguard-line fatalguard-grouped sev-' + (classify.severity(g.sample) || 'none'))
      row.appendChild(el('span', 'fatalguard-count', '×' + g.count))
      row.appendChild(el('span', 'fatalguard-grouped-msg', g.sample))
      if (g.last_seen) row.appendChild(el('span', 'fatalguard-seen', ' · ' + fmtAge(g.last_seen)))
      wrap.appendChild(row)
    })
    setBody(wrap)
  }

  function makeLogRow(line) { // B206: per-row copy affordance
    var row = el('div', 'fatalguard-line sev-' + (classify.severity(line) || 'none'))
    row.textContent = line
    var cp = el('button', 'fatalguard-copy', '⎘')
    cp.type = 'button'; cp.title = 'Copy line'; cp.setAttribute('aria-label', 'Copy line')
    ;(function (l) { cp.addEventListener('click', function (e) { e.stopPropagation(); copy(l) }) })(line)
    row.appendChild(cp)
    return row
  }

  // B162: render source.warning as a banner above content when present
  function renderSourceWarning() {
    if (!current || current.type !== 'log') return
    var s = sources.filter(function (x) { return x.id === current.id })[0]
    if (!s || !s.warning) return
    var banner = el('div', 'fatalguard-warning', '⚠ ' + s.warning)
    bodyEl.insertBefore(banner, bodyEl.firstChild)
  }

  function renderLines(res) { // B206: each row has copy() affordance via makeLogRow
    var lines = (res && res.lines) || []
    if (res && res.note && !lines.length) return setBody(emptyLogNode(res.note))
    var filtered = lines.filter(matchesFilter) // Apply tag filter
    if (!filtered.length) {
      var s = sources.filter(function (x) { return x.id === current.id })[0]
      var msg = selectedTag ? 'No entries match tag [' + selectedTag + ']'
        : (status && status.log_errors_ini === '0') ? 'Logging is on for this tool but php log_errors is 0 — open the Status tab.'
        : 'No entries yet. Try the Status tab → “Write a test line”.'
      setBody(emptyLogNode(s && !s.exists ? 'Log file not created yet. ' + msg : msg))
      renderSourceWarning()
      return
    }
    var pre = el('div', 'fatalguard-lines')
    filtered.forEach(function (line) { pre.appendChild(makeLogRow(line)) })
    setBody(pre)
    renderSourceWarning() // B162: banner after body is set
  }

  // B206: build one fatal card (shared by renderFatals + renderFatalsAppend so
  // "Load more" appends without clobbering the existing list).
  function buildFatalCard(f) {
    var card = el('div', 'fatalguard-fatal sev-' + classify.severityForType(f.type))
    var msg = el('div', 'fatalguard-fatal-msg')
    if (f.count && f.count > 1) msg.appendChild(el('span', 'fatalguard-count', '×' + f.count))
    if (f.origin) msg.appendChild(el('span', 'fatalguard-origin', f.origin))
    msg.appendChild(document.createTextNode(f.message || ''))
    card.appendChild(msg)
    var metaText = (f.file || '') + ':' + (f.line || '') + '  ·  ' + (f.url || '') + '  ·  ' + (f.last_seen ? fmtAge(f.last_seen) : (f.time || ''))
    card.appendChild(el('div', 'fatalguard-fatal-meta', metaText))
    var cp = el('button', 'fatalguard-copy', '⎘')
    cp.type = 'button'; cp.title = 'Copy fatal'; cp.setAttribute('aria-label', wp.i18n.__( 'Copy fatal to clipboard', 'fatalguard' ))
    ;(function (t) { cp.addEventListener('click', function (e) { e.stopPropagation(); copy(t) }) })((f.message || '') + '\n' + (f.file || '') + ':' + (f.line || '') + '\n' + (f.time || ''))
    card.appendChild(cp)
    return card
  }

  function renderFatals(fatals, res) { // B206: per-card copy() affordance + B163: Load more
    if (!fatals.length) return setBody(el('div', 'fatalguard-empty', 'No fatal errors captured. 🎉'))
    var wrap = el('div', 'fatalguard-fatals')
    fatals.forEach(function (f) { wrap.appendChild(buildFatalCard(f)) })
    // B163: Load more button if a full page was returned (more may exist)
    if (fatals.length >= FATALS_PAGE) {
      var more = el('button', 'fatalguard-load-more button', 'Load more')
      more.type = 'button'
      more.addEventListener('click', function () { loadFatals(true) })
      wrap.appendChild(more)
    }
    setBody(wrap)
  }

  function renderFatalsAppend(fatals, res) {
    // B163: append cards to existing list, replace/add Load more button
    var wrap = bodyEl && bodyEl.querySelector('.fatalguard-fatals')
    if (!wrap) return renderFatals(fatals, res)
    var oldMore = wrap.querySelector('.fatalguard-load-more')
    if (oldMore) wrap.removeChild(oldMore)
    fatals.forEach(function (f) { wrap.appendChild(buildFatalCard(f)) })
    if (fatals.length >= FATALS_PAGE) {
      var more = el('button', 'fatalguard-load-more button', 'Load more')
      more.type = 'button'
      more.addEventListener('click', function () { loadFatals(true) })
      wrap.appendChild(more)
    }
  }

  function renderStatus() {
    var wrap = el('div', 'fatalguard-status')
    if (!status) { wrap.appendChild(el('div', 'fatalguard-empty', 'No status available.')); return setBody(wrap) }

    var rows = [
      ['Telegram alerts', status.telegram === 'configured' ? 'configured ✓' : 'not configured'],
      ['PHP log_errors', status.log_errors_ini === '1' ? 'on ✓' : 'OFF (' + status.log_errors_ini + ')'],
      ['Managed log', status.managed_log + (status.managed_writable ? ' (writable ✓)' : ' (NOT writable ✗)')],
      ['Fatal capture', status.fatal_capture ? 'armed ✓' : 'DISABLED by site constant/filter'],
      ['Drop-in', status.dropin_foreign ? 'a foreign fatal-error-handler.php is present — capture deferred' : (status.dropin_ours ? 'installed ✓' : 'not installed yet')],
      ['Display errors', status.display_errors ? 'on' : 'off'],
      ['Environment', status.environment]
    ]
    if (status.disabled) rows.unshift(['DISABLED', 'Feature is off via the DISABLE kill-switch. Delete it to re-enable.'])
    rows.forEach(function (r) {
      var line = el('div', 'fatalguard-status-row')
      line.appendChild(el('span', 'fatalguard-status-k', r[0]))
      line.appendChild(el('span', 'fatalguard-status-v', r[1]))
      wrap.appendChild(line)
    })

    var actions = el('div', 'fatalguard-status-actions')
    var test = el('button', 'button', 'Write a test line')
    test.type = 'button'
    test.addEventListener('click', function () {
      test.disabled = true
      api('/test-line', {method: 'POST'})
        .then(function (r) { flash(r.observed ? 'Test line captured ✓' : 'Wrote a line but did not see it: ' + (r.reason || '')) })
        .catch(function (e) { flash('Error: ' + e.message) })
        .finally(function () { test.disabled = false })
    })
    var tg = el('button', 'button', 'Send test Telegram')
    tg.type = 'button'
    tg.addEventListener('click', function () {
      tg.disabled = true
      api('/test-telegram', {method: 'POST'})
        .then(function (r) { flash(r.message) })
        .catch(function (e) { flash('Error: ' + e.message) })
        .finally(function () { tg.disabled = false })
    })
    actions.appendChild(test)
    actions.appendChild(tg)
    wrap.appendChild(actions)
    setBody(wrap)
  }

  function flash(msg) {
    var f = document.getElementById('fatalguard-flash') || el('div', '', '')
    f.id = 'fatalguard-flash'
    // B191: announce action feedback to screen readers
    if (!f.getAttribute('aria-live')) {
      f.setAttribute('aria-live', 'polite')
      f.setAttribute('aria-atomic', 'true')
    }
    f.textContent = msg
    if (!f.parentNode) (footEl || headEl).appendChild(f)
    clearTimeout(f._t)
    f._t = setTimeout(function () { f.textContent = '' }, 6000)
  }

  // B206: copy text to clipboard with flash feedback
  function copy(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { flash('Copied.') }).catch(function () { flash('Copy failed.') })
    } else {
      try {
        var ta = document.createElement('textarea')
        ta.value = text
        ta.style.position = 'fixed'
        ta.style.opacity = '0'
        document.body.appendChild(ta)
        ta.select()
        document.execCommand('copy')
        document.body.removeChild(ta)
        flash('Copied.')
      } catch (e) { flash('Copy failed.') }
    }
  }

  function renderError(e) {
    var msg = (e && e.message) || 'Request failed'
    if (e && (e.code === 'rest_forbidden' || e.status === 401 || e.status === 403)) msg = 'Session expired or no access — reload the page.'
    setBody(el('div', 'fatalguard-empty', 'Error: ' + msg))
  }

  function setBody(node) {
    bodyEl.textContent = ''
    bodyEl.appendChild(node)
  }

  function startLive() {
    stopLive()
    liveTimer = setInterval(function () {
      if (overlay.hidden) return
      if (minimized) return loadPeek() // keep the collapsed peek fresh
      if (current && current.type === 'log') loadTail()
      else if (current && current.type === 'fatals') loadFatals()
    }, 2000)
  }
  function stopLive() {
    if (liveTimer) { clearInterval(liveTimer); liveTimer = null }
  }

  // Clicking empty header space (not a control, tab, or input) toggles lower/raise,
  // the same as the min button — a bigger, more obvious hit target.
  function headToggle(e) {
    if (!headEl || !e.target.closest) return
    if (!e.target.closest('#fatalguard-head')) return
    if (e.target.closest('button, a, input, select, .fatalguard-tab')) return
    setMin(!minimized)
    persist()
    if (minimized) loadPeek()
    else selectCurrent()
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('#fatalguard-overlay button[data-act]')
    if (!btn) return headToggle(e)
    var act = btn.getAttribute('data-act')
    if (act === 'close') return close()
    if (act === 'min') {
      setMin(!minimized)
      persist()
      if (minimized) loadPeek()       // lowered → show the last line in the bar
      else selectCurrent()            // raised → load the active tab
      return
    }
    if (act === 'refresh') return selectCurrent()
    if (act === 'live') {
      var on = btn.getAttribute('aria-pressed') === 'true'
      btn.setAttribute('aria-pressed', on ? 'false' : 'true')
      btn.textContent = on ? wp.i18n.__( 'Live: Off', 'fatalguard' ) : wp.i18n.__( 'Live: On', 'fatalguard' )
      btn.classList.toggle('is-on', !on)
      on ? stopLive() : startLive()
      return
    }
    if (act === 'clear') {
      if (current && current.type === 'fatals') {
        // B108: confirm before irreversibly clearing fatals history
        if (!confirm('Permanently clear all captured fatals? This cannot be undone.')) return
        setBody(el('div', 'fatalguard-empty', 'Cleared.'))
        api('/fatals-clear', {method: 'POST'})
          .then(function () { flash('Fatals cleared.'); loadFatals() })
          .catch(function (e2) { flash('Clear failed: ' + e2.message); loadFatals() })
        return
      }
      if (!current || current.type !== 'log') return flash('Select a log or Fatals tab to clear.')
      var csrc = sources.filter(function (s) { return s.id === current.id })[0]
      if (csrc && csrc.clearable) {
        // B108: confirm before truncating the log file
        if (!confirm('Permanently clear this log? This cannot be undone.')) return
        // Truncate the file and empty the view immediately — no reload.
        setBody(el('div', 'fatalguard-empty', 'Cleared.'))
        api('/clear', {method: 'POST', data: {source: current.id}})
          .then(function () { flash('Cleared.'); loadTail() })
          .catch(function (e2) { flash('Clear failed: ' + e2.message); loadTail() })
      } else {
        // Read-only source (e.g. a stream) — append a clear marker instead.
        api('/clear-mark', {method: 'POST', data: {source: current.id}})
          .then(function () { flash('Clear marker added.'); loadTail() })
          .catch(function (e2) { flash('Clear failed: ' + e2.message) })
      }
      return
    }
  })

  document.addEventListener('keydown', function (e) {
    if (!overlay || overlay.hidden) return
    // B216: arrow-key navigation within the tablist
    if ((e.key === 'ArrowLeft' || e.key === 'ArrowRight') && tabsEl && tabsEl.contains(document.activeElement)) {
      var tabs = Array.prototype.slice.call(tabsEl.children)
      var focusedIdx = tabs.indexOf(document.activeElement)
      if (focusedIdx !== -1) {
        var next = e.key === 'ArrowRight'
          ? (focusedIdx + 1) % tabs.length
          : (focusedIdx - 1 + tabs.length) % tabs.length
        tabs[next].focus()
        tabs[next].click()
        e.preventDefault()
      }
      return
    }
    // B165: don't close the overlay when Escape is pressed inside an input/select
    if (e.key === 'Escape') {
      if (e.target && e.target.closest && e.target.closest('#fatalguard-overlay input, #fatalguard-overlay select')) return
      close()
    }
  })

  // Capture JS errors and send to the log.
  // Throttle: 5 s debounce (one POST at most every 5 seconds) + per-message dedup.
  var _jsLogLastSent = 0
  var _jsLogSeen = {}
  function _sendJsLog(msg, level) {
    if (!apiFetch) return
    var now = Date.now()
    if (_jsLogSeen[msg] || now - _jsLogLastSent < 5000) return
    _jsLogSeen[msg] = true
    _jsLogLastSent = now
    apiFetch({path: NS + '/log-js', method: 'POST', data: {message: msg, level: level}}).catch(function () {})
  }
  window.addEventListener('error', function (e) {
    var msg = (e.filename || 'unknown') + ':' + (e.lineno || 0) + ' ' + (e.message || 'Unknown error')
    _sendJsLog(msg, 'error')
  })
  window.addEventListener('unhandledrejection', function (e) {
    var msg = 'Unhandled Promise: ' + (e.reason || 'unknown')
    _sendJsLog(msg, 'error')
  })

  // The admin-bar "Logs" node toggles the overlay (bound here — no inline onclick).
  document.addEventListener('click', function (e) {
    var bar = e.target.closest && e.target.closest('#wp-admin-bar-fatalguard a')
    if (!bar) return
    e.preventDefault()
    toggle()
  })

  // On a fresh page load, reopen (lowered) if it was open before navigating.
  function init() {
    if (!ready()) return
    var st = restore()
    if (st && st.open) {
      if (st.tab) current = st.tab
      open({min: true})
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init)
  } else {
    init()
  }

  // Let a companion script (e.g. FatalGuard's connect panel) add a tab without
  // forking this file: register {id,label,render(container)}; it appears after the
  // built-in tabs. No-op unless something calls it, so internal sites are unchanged.
  function registerTab(id, label, render) {
    if (!id || typeof render !== 'function') return
    if (extraTabs.some(function (t) { return t.id === id })) return
    extraTabs.push({id: id, label: label || id, render: render})
    if (overlay && tabsEl && !overlay.hidden) buildTabs() // live-add if already open
  }

  window.fatalguardDebugLogs = {toggle: toggle, open: open, close: close, registerTab: registerTab}
})()
