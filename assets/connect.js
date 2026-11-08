/**
 * FatalGuard — Telegram connect wizard. ONE component, two mounts: the settings
 * page (#fatalguard-connect-root) and the log-popup "Alerts" tab (registered via
 * the overlay's registerTab seam). All calls hit the site's own REST routes,
 * which talk to the user's own bot. No shared bot, no hosted server.
 */
(function () {
  var D = window.fatalguardConnectData
  if (!D || !D.rest) return

  // Shared state (one instance across both mounts).
  var state = {
    phase: D.configured ? 'connected' : 'idle', // idle|resolving|pairing|pick|connected
    token: '',
    username: '',
    chats: [],
    chatId: D.chatId || '',
    chatTitle: D.chatTitle || '',
    chatUsername: D.chatUsername || '',
    error: '',
    note: '',
    busy: false
  }
  var containers = []
  var pollTimer = null
  var pollUntil = 0

  function el(tag, cls, text) {
    var n = document.createElement(tag)
    if (cls) n.className = cls
    if (text != null) n.textContent = text
    return n
  }
  function nowSec() { return Math.floor(Date.now() / 1000) }

  function call(method, body) {
    return fetch(D.rest + method, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.nonce || '' },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Bad response' } }) })
  }

  function set(patch) { Object.assign(state, patch); rerender() }
  function rerender() {
    containers = containers.filter(function (c) { return c && c.isConnected })
    containers.forEach(draw)
  }
  function mount(container) {
    if (containers.indexOf(container) === -1) containers.push(container)
    draw(container)
  }

  // --- actions -------------------------------------------------------------
  function resolve() {
    var t = (state.token || '').trim()
    if (!t) return set({ error: D.i18n.pasteBotTokenFirst })
    set({ busy: true, error: '', note: '' })
    call('resolve', { token: t }).then(function (r) {
      if (!r || !r.ok) return set({ busy: false, error: (r && r.message) || D.i18n.couldNotVerifyToken })
      set({ busy: false, phase: 'pairing', username: r.username || '', error: '' })
      startPolling()
    }).catch(function () { set({ busy: false, error: D.i18n.networkError }) })
  }

  function startPolling() {
    stopPolling()
    pollUntil = nowSec() + 60
    poll()
    pollTimer = setInterval(poll, 2500)
  }
  function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null } }

  function poll() {
    if (nowSec() > pollUntil) {
      stopPolling()
      return set({ note: D.i18n.noChatDetected })
    }
    call('detect', { token: state.token }).then(function (r) {
      if (!r || !r.ok) { if (r && r.message) state.error = r.message; return }
      if (r.chats && r.chats.length === 1) { stopPolling(); saveChat(r.chats[0].id) }
      else if (r.chats && r.chats.length > 1) { stopPolling(); set({ phase: 'pick', chats: r.chats }) }
    }).catch(function () {})
  }

  function saveChat(id) {
    set({ busy: true, error: '' })
    call('save', { token: state.token, chat_id: String(id) }).then(function (r) {
      if (!r || !r.ok) return set({ busy: false, error: (r && r.message) || D.i18n.saveFailed })
      syncSettingsForm(String(id))
      set({
        busy: false, phase: 'connected', chatId: String(id), note: '',
        chatTitle: (r && r.title) || '', chatUsername: (r && r.username) || ''
      })
    }).catch(function () { set({ busy: false, error: D.i18n.networkError }) })
  }

  function disconnect() {
    if (!window.confirm(D.i18n.disconnectConfirm)) return
    set({ busy: true })
    call('disconnect', {}).then(function () {
      syncSettingsForm('')
      stopPolling()
      set({ busy: false, phase: 'idle', token: '', username: '', chats: [], chatId: '', chatTitle: '', chatUsername: '', note: '', error: '' })
    })
  }

  function sendTest(btn) {
    btn.disabled = true
    var old = btn.textContent
    btn.textContent = D.i18n.sending
    fetch(D.test, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': D.nonce || '' } })
      .then(function (r) { return r.json() })
      .then(function (r) { flash(btn, (r && r.message) || (r && r.ok ? 'Sent ✓' : 'Failed')) })
      .catch(function () { flash(btn, 'Network error') })
      .finally(function () { btn.disabled = false; btn.textContent = old })
  }

  // Keep the settings-page form in sync so a later "Save Changes" won't clobber
  // the chat id we just persisted over REST. (The token field is blank-keeps.)
  function syncSettingsForm(chatId) {
    var input = document.querySelector('[name="fatalguard_settings[telegram_chat_id]"]')
    if (input) input.value = chatId
  }

  // --- rendering -----------------------------------------------------------
  function draw(c) {
    c.textContent = ''
    c.classList.add('fatalguard-connect') // keep the popup's fatalguard-ext class too
    // Log-popup "Alerts" tab: lead with a summary of ALL alert channels (Telegram
    // + Pro channels), each with a live status, before the Telegram connect UI.
    if (c.getAttribute('data-fg-alerts') === '1' && D.channels && D.channels.length) drawChannels(c)
    if (state.phase === 'connected') { drawConnected(c); return }
    drawWizard(c)
  }

  // Channel roster with a status dot each: green = active, red = failing,
  // grey = not set up. Data comes from the server (fatalguardConnectData.channels).
  function drawChannels(c) {
    var wrap = el('div', 'fgc-channels')
    wrap.appendChild(el('h3', 'fgc-h', D.i18n.channelsHeading))
    var ul = el('ul', 'fgc-chan-list')
    D.channels.forEach(function (ch) {
      // locked (Pro channel on the free build) → a "Pro" badge; otherwise live status.
      var st = ch.locked ? 'pro' : (ch.failing ? 'failing' : (ch.configured ? 'active' : 'off'))
      var li = el('li', 'fgc-chan' + (ch.locked ? ' fgc-chan--locked' : ''))
      var dot = el('span', 'fgc-chan__dot fgc-chan__dot--' + st)
      dot.setAttribute('aria-hidden', 'true')
      li.appendChild(dot)
      li.appendChild(el('span', 'fgc-chan__name', ch.label))
      var txt = ch.locked ? D.i18n.chStatusPro
        : (ch.failing ? D.i18n.chStatusFailing : (ch.configured ? D.i18n.chStatusActive : D.i18n.chStatusOff))
      li.appendChild(el('span', 'fgc-chan__status fgc-chan__status--' + st, txt))
      ul.appendChild(li)
    })
    wrap.appendChild(ul)
    c.appendChild(wrap)
  }

  function drawConnected(c) {
    c.appendChild(el('h3', 'fgc-h', D.i18n.telegramConnected))
    var p = el('p', 'fgc-muted')
    p.appendChild(document.createTextNode(D.i18n.fatalAlertsGoTo))
    p.appendChild(chatLabel())
    p.appendChild(document.createTextNode('.'))
    c.appendChild(p)
    if (!state.chatTitle) resolveChatMeta() // already-connected load — fill the name live
    var row = el('div', 'fgc-row')
    var test = el('button', 'button button-primary', D.i18n.sendTestMessage)
    test.type = 'button'
    test.addEventListener('click', function () { sendTest(test) })
    var dis = el('button', 'button', D.i18n.disconnect)
    dis.type = 'button'
    dis.disabled = !!state.busy
    dis.addEventListener('click', disconnect)
    row.appendChild(test)
    row.appendChild(dis)
    c.appendChild(row)
    appendMsg(c)
  }

  function drawWizard(c) {
    c.appendChild(el('h3', 'fgc-h', D.i18n.connectTelegramAlerts))

    // Step 1 — create the bot (guided; BotFather can't be automated).
    var d = el('details', 'fgc-details')
    var sum = el('summary', null, D.i18n.step1CreateBot)
    d.appendChild(sum)
    var ol = el('ol', 'fgc-steps')
    ;[
      D.i18n.step1OpenBotFather,
      D.i18n.step1SendNewbot,
      D.i18n.step1GiveName,
      D.i18n.step1GiveUsername,
      D.i18n.step1CopyToken
    ].forEach(function (s) { ol.appendChild(el('li', null, s)) })
    d.appendChild(ol)
    var bf = el('a', 'button', D.i18n.openBotFather)
    bf.href = D.botFather; bf.target = '_blank'; bf.rel = 'noopener'
    var cp = el('button', 'button', D.i18n.copyNewbot)
    cp.type = 'button'
    cp.addEventListener('click', function () { copy('/newbot', cp) })
    var bfrow = el('div', 'fgc-row')
    bfrow.appendChild(bf); bfrow.appendChild(cp)
    d.appendChild(bfrow)
    if (state.phase === 'idle') d.open = true
    c.appendChild(d)

    // Step 2 — paste token.
    c.appendChild(el('label', 'fgc-label', D.i18n.step2PasteToken))
    var tokRow = el('div', 'fgc-row')
    var input = el('input', 'fgc-input regular-text')
    input.type = 'text'
    input.placeholder = '123456789:AA...'
    input.autocomplete = 'off'
    input.spellcheck = false
    input.value = state.token
    input.addEventListener('input', function (e) { state.token = e.target.value })
    var btn = el('button', 'button button-primary', state.phase === 'pairing' ? D.i18n.reCheck : D.i18n.connect)
    btn.type = 'button'
    btn.disabled = !!state.busy
    btn.addEventListener('click', resolve)
    tokRow.appendChild(input)
    tokRow.appendChild(btn)
    c.appendChild(tokRow)

    // Step 3 — pair (after the token resolves).
    if (state.phase === 'pairing' || state.phase === 'pick') {
      var deep = 'https://t.me/' + state.username + '?start=fatalguard'
      c.appendChild(el('label', 'fgc-label', D.i18n.step3OpenBot))
      var pairRow = el('div', 'fgc-row')
      var open = el('a', 'button button-primary', D.i18n.openAt + state.username)
      open.href = deep; open.target = '_blank'; open.rel = 'noopener'
      var copyLink = el('button', 'button', D.i18n.copyLink)
      copyLink.type = 'button'
      copyLink.addEventListener('click', function () { copy(deep, copyLink) })
      pairRow.appendChild(open)
      pairRow.appendChild(copyLink)
      c.appendChild(pairRow)

      if (state.phase === 'pairing') {
        c.appendChild(el('p', 'fgc-muted fgc-wait', state.note ? state.note : D.i18n.waitingForStart))
        if (state.note) {
          var retry = el('button', 'button', D.i18n.retry)
          retry.type = 'button'
          retry.addEventListener('click', function () { set({ note: '' }); startPolling() })
          c.appendChild(retry)
        }
      }

      if (state.phase === 'pick') {
        c.appendChild(el('p', 'fgc-muted', D.i18n.whichChat))
        state.chats.forEach(function (ch) {
          var b = el('button', 'button', (ch.title || ch.id) + '  ·  ' + (ch.type || ''))
          b.type = 'button'
          b.addEventListener('click', function () { saveChat(ch.id) })
          c.appendChild(b)
        })
      }
    }

    appendMsg(c)
  }

  // The destination as a name — a t.me link when the chat has a @username,
  // otherwise bold text. Falls back to "chat <id>" until the name resolves.
  function chatLabel() {
    var name = state.chatTitle || ('chat ' + state.chatId)
    if (state.chatUsername) {
      var a = el('a', null, name)
      a.href = 'https://t.me/' + state.chatUsername
      a.target = '_blank'
      a.rel = 'noopener'
      return a
    }
    return el('strong', null, name)
  }

  // Resolve the saved chat's name over REST (token stays server-side).
  function resolveChatMeta() {
    if (resolveChatMeta._done) return
    resolveChatMeta._done = true
    call('chat', {}).then(function (r) {
      if (r && r.ok && r.title) set({ chatTitle: r.title, chatUsername: r.username || '' })
    }).catch(function () {})
  }

  function appendMsg(c) {
    if (state.error) c.appendChild(el('p', 'fgc-err', state.error))
  }

  function flash(btn, msg) {
    var p = btn.parentNode
    var f = p.querySelector('.fgc-flash') || el('span', 'fgc-flash')
    f.textContent = ' ' + msg
    if (!f.parentNode) p.appendChild(f)
    clearTimeout(f._t)
    f._t = setTimeout(function () { f.textContent = '' }, 6000)
  }

  function copy(text, btn) {
    try {
      navigator.clipboard.writeText(text).then(function () { flash(btn, 'copied') })
    } catch (e) {}
  }

  // --- mounts --------------------------------------------------------------
  // Settings page.
  function mountSettings() {
    var root = document.getElementById('fatalguard-connect-root')
    if (root) mount(root)
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountSettings)
  } else {
    mountSettings()
  }

  // Log-popup "Alerts" tab (wait for the overlay's seam to exist).
  var tries = 0
  ;(function register() {
    var api = window.fatalguardDebugLogs
    if (api && api.registerTab) {
      api.registerTab('alerts', 'Alerts', function (c) { c.setAttribute('data-fg-alerts', '1'); mount(c) })
      return
    }
    if (tries++ < 40) setTimeout(register, 100)
  })()
})()
