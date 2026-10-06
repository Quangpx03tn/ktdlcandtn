let quizSettings = {
  quiz_duration_minutes: 6,
  pass_percent: 50,
  excellent_percent: 80,
  fast_warning_seconds: 30
};
let QUIZ_DURATION_SECONDS = quizSettings.quiz_duration_minutes * 60;
let activeQuestionBank = [];

async function loadQuestionBank() {
  try {
    const response = await fetch('question_api.php', { cache: 'no-store' });
    const payload = await response.json();
    activeQuestionBank = payload.success && Array.isArray(payload.data) && payload.data.length
      ? payload.data
      : QUESTION_BANK;
  } catch (error) {
    console.warn('Không tải được ngân hàng câu hỏi, sử dụng bộ đề mặc định.', error);
    activeQuestionBank = QUESTION_BANK;
  }
  return activeQuestionBank;
}

async function loadPublicSettings() {
  try {
    const response = await fetch('admin_api.php?action=public_settings', { cache: 'no-store' });
    const payload = await response.json();
    if (payload.success && payload.data) {
      quizSettings = { ...quizSettings, ...payload.data };
      QUIZ_DURATION_SECONDS = Number(quizSettings.quiz_duration_minutes) * 60;
    }
  } catch (error) {
    console.warn('Không tải được cài đặt, sử dụng giá trị mặc định.', error);
  }
}
loadPublicSettings();

// ===== Trạng thái ứng dụng =====
const state = {
  email: '',
  selectedUnit: '',
  selectedBlock: '',
  examToken: '',
  roomOpenAt: 0,
  roomCloseAt: 0,
  examCode: '',
  fullname: '',
  rank: '',
  team: '',
  numQuestions: 20,
  questions: [],
  answers: {},
  currentQ: 0,
  startTime: null,
  timerInterval: null,
  isFinished: false,
  correct: 0,
  wrong: 0,
  durationSeconds: 0,
  autoSubmitted: false,
  percent: 0,
  passed: false,
  grade: ''
};
let roomScheduleByUnit = {};

// ===== DOM =====
const screens = {
  login: document.getElementById('login-screen'),
  unit: document.getElementById('unit-screen'),
  info: document.getElementById('info-screen'),
  quiz: document.getElementById('quiz-screen'),
  result: document.getElementById('result-screen')
};

const presenceDeviceId = localStorage.getItem('ca_presence_device') || `device_${crypto.randomUUID ? crypto.randomUUID() : Date.now() + '_' + Math.random().toString(36).slice(2)}`;
localStorage.setItem('ca_presence_device', presenceDeviceId);
const presenceClientId = sessionStorage.getItem('ca_presence_client') || `tab_${crypto.randomUUID ? crypto.randomUUID() : Date.now() + '_' + Math.random().toString(36).slice(2)}`;
sessionStorage.setItem('ca_presence_client', presenceClientId);
const presenceVisitId = sessionStorage.getItem('ca_presence_visit') || `visit_${Date.now()}_${Math.random().toString(36).slice(2)}`;
sessionStorage.setItem('ca_presence_visit', presenceVisitId);

function presenceStage() {
  return screens.quiz.classList.contains('active') && !state.isFinished ? 'exam' : 'online';
}

function renderPresence(stats) {
  document.querySelectorAll('[data-live-online]').forEach(el => { el.textContent = stats.online ?? 0; });
  document.querySelectorAll('[data-live-examining]').forEach(el => { el.textContent = stats.examining ?? 0; });
  document.querySelectorAll('[data-live-visits]').forEach(el => { el.textContent = stats.total_visits ?? 0; });
}

async function sendPresence(stage = presenceStage()) {
  try {
    const response = await fetch('presence_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      cache: 'no-store',
      body: JSON.stringify({
        client_id: presenceClientId,
        device_id: presenceDeviceId,
        visit_id: presenceVisitId,
        stage,
        email: state.email,
        fullname: state.fullname,
        unit: state.selectedUnit
      })
    });
    const payload = await response.json();
    if (payload.success) renderPresence(payload);
    return payload;
  } catch (error) {
    console.warn('Không cập nhật được thống kê trực tuyến.', error);
    return null;
  }
}

sendPresence('online');
setInterval(() => sendPresence(), 10000);
window.addEventListener('pagehide', () => {
  navigator.sendBeacon('presence_api.php', new Blob([JSON.stringify({ client_id: presenceClientId, stage: 'offline' })], { type: 'application/json' }));
});

function showScreen(name) {
  Object.values(screens).forEach(s => s.classList.remove('active'));
  screens[name].classList.add('active');
}

let googleOAuthInitialized = false;
let googleOAuthConfig = null;

function completeLogin(email, suggestedName = '') {
  state.email = email;
  document.getElementById('user-email-display').textContent = email;
  document.getElementById('email').value = email;
  if (suggestedName && !document.getElementById('fullname').value) {
    document.getElementById('fullname').value = suggestedName;
  }
  localStorage.setItem('ca_user_email', email);
  showScreen('unit');
  renderUnits();
}

async function handleGoogleCredential(response) {
  const status = document.getElementById('google-login-status');
  status.textContent = 'Đang xác minh tài khoản Google…';
  try {
    const verifyResponse = await fetch('google_auth.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ credential: response.credential })
    });
    const payload = await verifyResponse.json();
    if (!payload.success) throw new Error(payload.message || 'Không xác minh được tài khoản Google.');
    completeLogin(payload.profile.email, payload.profile.name || '');
    status.textContent = 'Đã xác minh Gmail thành công.';
  } catch (error) {
    status.textContent = error.message || 'Đăng nhập Google không thành công.';
    await SmartDialog.alert(status.textContent, { title: 'Không thể đăng nhập Google', type: 'danger' });
  }
}

window.initGoogleOAuth = async function initGoogleOAuth() {
  const status = document.getElementById('google-login-status');
  if (!status || googleOAuthInitialized) return;
  try {
    if (!googleOAuthConfig) {
      const response = await fetch('google_config.php', { cache: 'no-store' });
      googleOAuthConfig = await response.json();
    }
    if (!googleOAuthConfig.enabled) {
      status.textContent = 'Google OAuth chưa cấu hình — vẫn có thể đăng nhập thử nghiệm bên dưới.';
      return;
    }
    if (!window.google?.accounts?.id) {
      status.textContent = navigator.onLine ? 'Đang tải dịch vụ đăng nhập Google…' : 'Đang offline — sử dụng đăng nhập thử nghiệm.';
      return;
    }
    google.accounts.id.initialize({
      client_id: googleOAuthConfig.client_id,
      callback: handleGoogleCredential,
      auto_select: false,
      cancel_on_tap_outside: true
    });
    google.accounts.id.renderButton(document.getElementById('google-signin-button'), {
      type: 'standard', theme: 'outline', size: 'large', text: 'continue_with', shape: 'rectangular', width: 320, locale: 'vi'
    });
    googleOAuthInitialized = true;
    status.textContent = 'Gmail được xác minh trực tiếp bởi Google.';
  } catch (error) {
    status.textContent = 'Không tải được cấu hình Google — sử dụng đăng nhập thử nghiệm.';
  }
};

window.initGoogleOAuth();

// ===== Đăng nhập (mock Gmail) =====
document.getElementById('login-form').addEventListener('submit', (e) => {
  e.preventDefault();
  const email = document.getElementById('email').value.trim();
  const password = document.getElementById('password').value;

  if (!email.includes('@') || password.length < 4) {
    alert('Vui lòng nhập Gmail hợp lệ và mật khẩu ít nhất 4 ký tự.');
    return;
  }

  completeLogin(email);
});

// Tự động đăng nhập nếu đã có
window.addEventListener('DOMContentLoaded', () => {
  const saved = localStorage.getItem('ca_user_email');
  if (saved) {
    state.email = saved;
    document.getElementById('user-email-display').textContent = saved;
    document.getElementById('email').value = saved;
    showScreen('unit');
    renderUnits();
  }
});

document.getElementById('logout-btn').addEventListener('click', () => {
  localStorage.removeItem('ca_user_email');
  state.email = '';
  if (window.google?.accounts?.id) google.accounts.id.disableAutoSelect();
  showScreen('login');
});

// ===== Render danh sách đơn vị =====
function renderUnits() {
  const localUnits = UNITS['dia-phuong'].list;
  const northernCommuneNames = new Set([
    'Xã Bằng Thành', 'Xã Nghiên Loan', 'Xã Cao Minh', 'Xã Ba Bể',
    'Xã Chợ Rã', 'Xã Phúc Lộc', 'Xã Thượng Minh', 'Xã Đồng Phúc',
    'Xã Thượng Quan', 'Xã Bằng Vân', 'Xã Ngân Sơn', 'Xã Nà Phặc',
    'Xã Hiệp Lực', 'Xã Thuần Mang', 'Xã Chợ Đồn', 'Xã Yên Phong',
    'Xã Nghĩa Tá', 'Xã Phủ Thông', 'Xã Cẩm Giàng', 'Xã Vĩnh Thông',
    'Xã Bạch Thông', 'Xã Phong Quang', 'Xã Văn Lang', 'Xã Cường Lợi',
    'Xã Na Rì', 'Xã Trần Phú'
  ]);
  const communeUnits = localUnits.filter(unit => unit.startsWith('Xã '));
  const displayBlocks = [
    ['tham-muu', UNITS['tham-muu']],
    ['an-ninh', UNITS['an-ninh']],
    ['canh-sat', UNITS['canh-sat']],
    ['phuong', { list: localUnits.filter(unit => unit.startsWith('Phường ')) }],
    ['xa-bac', { list: communeUnits.filter(unit => northernCommuneNames.has(unit)) }],
    ['xa-nam', { list: communeUnits.filter(unit => !northernCommuneNames.has(unit)) }],
    ['du-phong', UNITS['du-phong']]
  ];

  for (const [key, block] of displayBlocks) {
    const container = document.getElementById(`list-${key}`);
    if (!container) continue;
    container.innerHTML = '';
    block.list.forEach(unit => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'unit-btn';
      btn.textContent = unit;
      btn.dataset.unit = unit;
      btn.dataset.search = normalizeSearchText(unit);
      const blockKey = key === 'phuong' || key === 'xa-bac' || key === 'xa-nam'
        ? 'dia-phuong'
        : key;
      btn.addEventListener('click', () => selectUnit(unit, blockKey));
      container.appendChild(btn);
    });
  }

  filterUnits(document.getElementById('unit-search')?.value || '');
  refreshRoomStatuses();
}

async function refreshRoomStatuses() {
  try {
    const response = await fetch('room_api.php?action=status', { cache: 'no-store' });
    const payload = await response.json();
    if (!payload.success) return;
    roomScheduleByUnit = {};
    document.querySelectorAll('.unit-btn').forEach(btn => {
      const unit = btn.dataset.unit;
      const open = Object.prototype.hasOwnProperty.call(payload.rooms || {}, unit)
        ? !!payload.rooms[unit]
        : !!payload.default_open;
      const scheduled = Number(payload.scheduled_open?.[unit] || 0);
      const scheduledClose = Number(payload.scheduled_close?.[unit] || 0);
      roomScheduleByUnit[unit] = { openAt: scheduled, closeAt: scheduledClose, open };
      const waiting = !open && scheduled * 1000 > Date.now();
      btn.disabled = !open && !waiting;
      btn.classList.toggle('room-locked', !open && !waiting);
      btn.classList.toggle('room-scheduled', waiting);
      btn.classList.toggle('room-open', open);
      btn.title = open
        ? (scheduledClose ? `Phòng đang mở · tự đóng lúc ${formatVietnamTime(scheduledClose)}` : 'Phòng thi đang mở')
        : waiting
          ? `Mở lúc ${formatVietnamTime(scheduled)}${scheduledClose ? ` · đóng lúc ${formatVietnamTime(scheduledClose)}` : ''}`
          : 'Phòng thi đang khóa';
    });
    if (state.selectedUnit) renderRoomScheduleNotice(state.selectedUnit);
  } catch (error) {
    console.warn('Không cập nhật được trạng thái phòng thi.', error);
  }
}

function formatVietnamTime(timestamp) {
  if (!timestamp) return '';
  return new Date(Number(timestamp) * 1000).toLocaleString('vi-VN', {
    timeZone: 'Asia/Ho_Chi_Minh', day:'2-digit', month:'2-digit', year:'numeric',
    hour:'2-digit', minute:'2-digit', hourCycle:'h23'
  });
}

function renderRoomScheduleNotice(unit) {
  const schedule = roomScheduleByUnit[unit] || { openAt:state.roomOpenAt, closeAt:state.roomCloseAt };
  const parts = [];
  if (schedule.openAt) parts.push(`Mở cửa: ${formatVietnamTime(schedule.openAt)}`);
  if (schedule.closeAt) parts.push(`Đóng cửa: ${formatVietnamTime(schedule.closeAt)}`);
  const message = parts.length
    ? `🕒 Lịch phòng thi ${unit}: ${parts.join(' · ')} (giờ Việt Nam). Người đã bắt đầu trước giờ đóng vẫn được hoàn thành và nộp bài.`
    : '';
  ['room-schedule-notice', 'exam-room-notice'].forEach(id => {
    const element = document.getElementById(id);
    if (!element) return;
    element.textContent = message;
    element.hidden = !message;
  });
}

setInterval(() => {
  if (screens.unit.classList.contains('active')) refreshRoomStatuses();
}, 5000);

async function requestRoomEntry(unit) {
  try {
    const response = await fetch(`room_api.php?action=enter&unit=${encodeURIComponent(unit)}`, { cache: 'no-store' });
    const payload = await response.json();
    if (!response.ok || !payload.success) {
      await SmartDialog.alert(payload.message || 'Phòng thi đang khóa.', {
        title: payload.code === 'ROOM_SCHEDULED' ? 'Phòng thi đã được hẹn giờ' : (payload.code === 'ROOM_CLOSED' ? 'Phòng thi đã đóng' : 'Phòng thi đang khóa'),
        type: payload.code === 'ROOM_SCHEDULED' ? 'warning' : 'info'
      });
      refreshRoomStatuses();
      return false;
    }
    state.examToken = payload.token;
    state.roomOpenAt = Number(payload.scheduled_open || 0);
    state.roomCloseAt = Number(payload.scheduled_close || 0);
    roomScheduleByUnit[unit] = { ...(roomScheduleByUnit[unit] || {}), openAt:state.roomOpenAt, closeAt:state.roomCloseAt, open:true };
    renderRoomScheduleNotice(unit);
    return true;
  } catch (error) {
    await SmartDialog.alert('Không kiểm tra được trạng thái phòng thi. Vui lòng thử lại.', { title: 'Lỗi kết nối', type: 'danger' });
    return false;
  }
}

function normalizeSearchText(value) {
  return value
    .toLocaleLowerCase('vi')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/đ/g, 'd')
    .trim();
}

function filterUnits(value) {
  const query = normalizeSearchText(value);
  let visibleCount = 0;

  document.querySelectorAll('.block-card[data-block]').forEach(card => {
    let cardVisibleCount = 0;
    card.querySelectorAll('.unit-btn').forEach(btn => {
      const matched = !query || btn.dataset.search.includes(query);
      btn.hidden = !matched;
      if (matched) cardVisibleCount++;
    });
    card.hidden = cardVisibleCount === 0;
    visibleCount += cardVisibleCount;
  });

  const emptyMessage = document.getElementById('unit-search-empty');
  if (emptyMessage) emptyMessage.hidden = visibleCount > 0;
}

document.getElementById('unit-search')?.addEventListener('input', (event) => {
  filterUnits(event.target.value);
});

async function selectUnit(unit, blockKey) {
  if (!(await requestRoomEntry(unit))) return;
  state.selectedUnit = unit;
  state.selectedBlock = blockKey;
  document.getElementById('selected-unit-name').textContent = unit;
  renderRoomScheduleNotice(unit);
  showScreen('info');
}

document.getElementById('back-to-unit').addEventListener('click', () => {
  showScreen('unit');
});

// ===== Form thông tin =====
document.getElementById('info-form').addEventListener('submit', (e) => {
  e.preventDefault();
  state.fullname = document.getElementById('fullname').value.trim();
  state.rank = document.getElementById('rank').value;
  state.team = document.getElementById('team').value.trim();
  state.numQuestions = parseInt(document.getElementById('num-questions').value);

  if (!state.fullname || !state.rank || !state.team) {
    alert('Vui lòng điền đầy đủ thông tin.');
    return;
  }

  startQuiz();
});

// ===== Quiz =====
async function startQuiz() {
  await loadPublicSettings();
  await loadQuestionBank();
  if (!(await requestRoomEntry(state.selectedUnit))) {
    showScreen('unit');
    return;
  }
  const presenceResult = await sendPresence('exam');
  if (presenceResult?.blocked) {
    await SmartDialog.alert(presenceResult.message, { title: 'Đã có bài thi trên thiết bị', type: 'warning' });
    return;
  }
  let assignedExam = null;
  try {
    const examResponse = await fetch(`exam_api.php?count=${encodeURIComponent(state.numQuestions)}`, { cache: 'no-store' });
    const examPayload = await examResponse.json();
    if (examPayload.success && examPayload.assigned) assignedExam = examPayload.data;
    if (!assignedExam) {
      await sendPresence('online');
      await SmartDialog.alert(examPayload.message || 'Ngân hàng đề thi chưa sẵn sàng. Vui lòng liên hệ cán bộ coi thi.', {
        title: examPayload.ready ? 'Không có bộ đề phù hợp' : 'Ngân hàng đề chưa được phát hành',
        type: 'warning'
      });
      return;
    }
  } catch (error) {
    await sendPresence('online');
    await SmartDialog.alert('Không kết nối được ngân hàng đề thi trên server. Vui lòng thử lại.', { title: 'Lỗi kết nối', type: 'danger' });
    return;
  }

  // ===== Gán bộ đề đã chia hoặc tạo đề ngẫu nhiên =====
  const numQ = Math.min(state.numQuestions, activeQuestionBank.length);

  // Lấy danh sách câu hỏi đã làm của email này (tránh trùng lặp)
  const usedKey = 'ca_used_q_' + (state.email || 'guest');
  let usedIds = JSON.parse(localStorage.getItem(usedKey) || '[]');

  // Tạo bản sao và gắn id tạm (index gốc)
  let pool = activeQuestionBank.map((q, idx) => ({ ...q, _id: idx }));

  // Ưu tiên câu chưa làm
  let unused = pool.filter(q => !usedIds.includes(q._id));
  let used = pool.filter(q => usedIds.includes(q._id));

  // Xáo trộn
  unused = unused.sort(() => Math.random() - 0.5);
  used = used.sort(() => Math.random() - 0.5);

  // Ghép: ưu tiên câu mới, nếu thiếu thì lấy câu cũ
  let selected = unused.slice(0, numQ);
  if (selected.length < numQ) {
    selected = selected.concat(used.slice(0, numQ - selected.length));
  }

  // Nếu vẫn thiếu (hiếm), lấy ngẫu nhiên toàn bộ
  if (selected.length < numQ) {
    selected = pool.sort(() => Math.random() - 0.5).slice(0, numQ);
  }

  // Xáo thứ tự câu hỏi lần cuối
  selected = selected.sort(() => Math.random() - 0.5);

  // Xáo thứ tự đáp án (A B C D) cho từng câu + cập nhật đáp án đúng
  const randomizedQuestions = selected.map(q => {
    const opts = q.options.map((text, i) => ({ text, origIdx: i }));
    // Fisher-Yates shuffle
    for (let i = opts.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [opts[i], opts[j]] = [opts[j], opts[i]];
    }
    const newOptions = opts.map(o => o.text);
    const newAnswer = opts.findIndex(o => o.origIdx === q.answer);
    return {
      q: q.q,
      options: newOptions,
      answer: newAnswer,
      _id: q._id
    };
  });

  state.examCode = assignedExam.code;
  state.questions = assignedExam.questions.map((question, index) => ({
    q: question.q,
    options: question.options,
    answer: Number(question.answer),
    _id: question.source_id ?? index
  }));
  document.getElementById('exam-code-display').textContent = `Mã đề: ${state.examCode}`;

  // Ghi nhận câu đã dùng cho email này
  const newUsed = [...new Set([...usedIds, ...state.questions.map(q => q._id)])];
  // Giữ tối đa 500 id gần nhất để không phình localStorage
  localStorage.setItem(usedKey, JSON.stringify(newUsed.slice(-500)));

  state.answers = {};
  state.currentQ = 0;
  state.startTime = Date.now();
  state.isFinished = false;
  state.autoSubmitted = false;

  if (state.timerInterval) clearInterval(state.timerInterval);
  const timerEl = document.getElementById('timer');
  timerEl.classList.remove('timer-warning');
  const initialMinutes = String(Math.floor(QUIZ_DURATION_SECONDS / 60)).padStart(2, '0');
  const initialSeconds = String(QUIZ_DURATION_SECONDS % 60).padStart(2, '0');
  timerEl.textContent = `Còn lại: ${initialMinutes}:${initialSeconds}`;
  state.timerInterval = setInterval(updateTimer, 1000);

  showScreen('quiz');
  renderQuestionNav();
  renderQuestion();
}

// ===== Bảng điều hướng câu hỏi (ô số) =====
function renderQuestionNav() {
  const grid = document.getElementById('question-nav-grid');
  const totalCountEl = document.getElementById('nav-total-count');
  if (!grid) return;

  grid.innerHTML = '';
  totalCountEl.textContent = state.questions.length;

  state.questions.forEach((q, idx) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'nav-q-btn';
    btn.textContent = idx + 1;
    btn.dataset.index = idx;
    btn.addEventListener('click', () => {
      state.currentQ = idx;
      renderQuestion();
    });
    grid.appendChild(btn);
  });

  updateQuestionNav();
}

function updateQuestionNav() {
  const grid = document.getElementById('question-nav-grid');
  const answeredCountEl = document.getElementById('nav-answered-count');
  if (!grid) return;

  const buttons = grid.querySelectorAll('.nav-q-btn');
  let answeredCount = 0;

  buttons.forEach((btn, idx) => {
    btn.classList.remove('current', 'answered');
    const isAnswered = state.answers[idx] !== undefined;
    if (isAnswered) {
      btn.classList.add('answered');
      answeredCount++;
    }
    if (idx === state.currentQ) {
      btn.classList.add('current');
    }
  });

  if (answeredCountEl) answeredCountEl.textContent = answeredCount;
}

function updateTimer() {
  const elapsed = Math.floor((Date.now() - state.startTime) / 1000);
  const remaining = Math.max(0, QUIZ_DURATION_SECONDS - elapsed);
  const m = String(Math.floor(remaining / 60)).padStart(2, '0');
  const s = String(remaining % 60).padStart(2, '0');
  const timerEl = document.getElementById('timer');

  timerEl.textContent = `Còn lại: ${m}:${s}`;
  timerEl.classList.toggle('timer-warning', remaining > 0 && remaining <= 60);

  if (remaining === 0) {
    clearInterval(state.timerInterval);
    state.timerInterval = null;
    finishQuiz(true);
  }
}

function renderQuestion() {
  const q = state.questions[state.currentQ];
  const total = state.questions.length;

  document.getElementById('question-counter').textContent = `Câu ${state.currentQ + 1}/${total}`;
  document.getElementById('question-text').textContent = `${state.currentQ + 1}. ${q.q}`;

  const optionsBox = document.getElementById('options-box');
  optionsBox.innerHTML = '';

  q.options.forEach((opt, idx) => {
    const label = document.createElement('label');
    label.className = 'option-item';
    if (state.answers[state.currentQ] === idx) label.classList.add('selected');

    const radio = document.createElement('input');
    radio.type = 'radio';
    radio.name = 'answer';
    radio.value = idx;
    if (state.answers[state.currentQ] === idx) radio.checked = true;

    radio.addEventListener('change', () => {
      state.answers[state.currentQ] = idx;
      document.querySelectorAll('.option-item').forEach(el => el.classList.remove('selected'));
      label.classList.add('selected');
      updateQuestionNav();
    });

    label.appendChild(radio);
    label.appendChild(document.createTextNode(String.fromCharCode(65 + idx) + '. ' + opt));
    optionsBox.appendChild(label);
  });

  document.getElementById('prev-btn').disabled = state.currentQ === 0;
  const isLast = state.currentQ === total - 1;
  document.getElementById('next-btn').style.display = isLast ? 'none' : 'inline-block';
  document.getElementById('submit-quiz-btn').style.display = isLast ? 'inline-block' : 'none';

  updateQuestionNav();
}

document.getElementById('prev-btn').addEventListener('click', () => {
  if (state.currentQ > 0) {
    state.currentQ--;
    renderQuestion();
  }
});

document.getElementById('next-btn').addEventListener('click', () => {
  if (state.currentQ < state.questions.length - 1) {
    state.currentQ++;
    renderQuestion();
  }
});

async function requestSubmitQuiz() {
  const unanswered = state.questions.length - Object.keys(state.answers).length;
  if (unanswered > 0) {
    if (!(await SmartDialog.confirm(
      `Bạn còn ${unanswered} câu chưa trả lời. Các câu này sẽ được tính là chưa làm nếu nộp bài ngay.`,
      { title: 'Xác nhận nộp bài', type: 'warning', confirmText: 'Nộp bài' }
    ))) {
      return;
    }
  }
  finishQuiz();
}

document.getElementById('submit-quiz-btn').addEventListener('click', requestSubmitQuiz);
document.getElementById('side-submit-quiz-btn').addEventListener('click', requestSubmitQuiz);

function finishQuiz(autoSubmitted = false) {
  if (state.isFinished) return;
  state.isFinished = true;
  state.autoSubmitted = autoSubmitted;
  clearInterval(state.timerInterval);
  state.timerInterval = null;

  const timerEl = document.getElementById('timer');
  timerEl.classList.remove('timer-warning');

  let correct = 0;
  state.questions.forEach((q, i) => {
    if (state.answers[i] === q.answer) correct++;
  });

  state.correct = correct;
  state.wrong = state.questions.length - correct;
  state.percent = Math.round((correct / state.questions.length) * 100);
  state.durationSeconds = Math.min(
    QUIZ_DURATION_SECONDS,
    Math.max(0, Math.floor((Date.now() - state.startTime) / 1000))
  );

  // Xếp loại theo tỷ lệ: dưới 50% = Không đạt,
  // từ 50% đến dưới 80% = Khá, từ 80% trở lên = Giỏi.
  if (state.percent < Number(quizSettings.pass_percent)) {
    state.grade = 'Không đạt';
    state.passed = false;
  } else if (state.percent < Number(quizSettings.excellent_percent)) {
    state.grade = 'Khá';
    state.passed = true;
  } else {
    state.grade = 'Giỏi';
    state.passed = true;
  }

  document.getElementById('res-fullname').textContent = state.fullname;
  document.getElementById('res-rank').textContent = state.rank;
  document.getElementById('res-team').textContent = state.team;
  document.getElementById('res-unit').textContent = state.selectedUnit;
  document.getElementById('res-exam-code').textContent = state.examCode;
  document.getElementById('res-total').textContent = `${state.questions.length}/${state.questions.length}`;
  document.getElementById('res-correct').textContent = state.correct;
  document.getElementById('res-wrong').textContent = state.wrong;
  document.getElementById('res-percent').textContent = state.percent + '%';

  // Hiển thị xếp loại
  const gradeEl = document.getElementById('res-grade');
  if (gradeEl) gradeEl.textContent = state.grade;

  const statusEl = document.getElementById('result-status');
  if (state.grade === 'Giỏi') {
    statusEl.textContent = '✓ GIỎI';
    statusEl.className = 'pass';
    statusEl.style.color = '#28a745';
  } else if (state.grade === 'Khá') {
    statusEl.textContent = '✓ KHÁ';
    statusEl.className = 'pass';
    statusEl.style.color = '#17a2b8';
  } else {
    statusEl.textContent = '✗ KHÔNG ĐẠT';
    statusEl.className = 'fail';
    statusEl.style.color = '#dc3545';
  }

  // Lưu vào localStorage / server (chỉ Admin mới xem & xuất được)
  saveResult();

  showScreen('result');
  sendPresence('online');

  if (autoSubmitted) {
    const note = document.querySelector('.result-actions p');
    if (note) {
      note.innerHTML = 'Đã hết thời gian 6 phút. Hệ thống đã tự động nộp bài; các câu chưa trả lời được tính là chưa làm.<br><strong>Chỉ Admin mới có quyền quản lý và tải file Excel.</strong>';
    }
  }
}

async function saveResult() {
  const payload = {
    email: state.email,
    unit: state.selectedUnit,
    fullname: state.fullname,
    rank: state.rank,
    team: state.team,
    total: state.questions.length,
    correct: state.correct,
    wrong: state.wrong,
    answered: Object.keys(state.answers).length,
    duration_seconds: state.durationSeconds,
    auto_submitted: state.autoSubmitted,
    room_token: state.examToken,
    exam_code: state.examCode,
    answers: state.questions.map((q, index) => ({
      question_id: q._id,
      selected: state.answers[index] ?? null
    })),
    percent: state.percent,
    passed: state.passed,
    grade: state.grade
  };

  // Gửi lên server — chỉ chấp nhận lần thi đầu tiên theo tài khoản + đơn vị
  try {
    const res = await fetch('save_result.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success) {
      console.log('Đã lưu kết quả lên server:', data.id);
      // Đánh dấu đã nộp thành công trên UI (nếu có)
      const note = document.querySelector('.result-actions p');
      if (note) {
        note.innerHTML = 'Kết quả đã được ghi nhận (lần thi đầu tiên).<br><strong>Chỉ Admin mới có quyền quản lý và tải file Excel.</strong>';
      }
    } else if (data.code === 'ALREADY_SUBMITTED') {
      alert(
        'Không ghi nhận kết quả này.\n\n' +
        'Tài khoản này đã có kết quả thi lần đầu tại đơn vị đã chọn' +
        (data.existing_time ? ' lúc ' + data.existing_time : '') +
        (data.existing_unit ? ' (đơn vị: ' + data.existing_unit + ')' : '') +
        '.\n\nHệ thống chỉ chấp nhận lần thi đầu tiên của mỗi tài khoản tại một đơn vị.'
      );
      const note = document.querySelector('.result-actions p');
      if (note) {
        note.innerHTML = '<span style="color:#dc3545;"><strong>Kết quả này KHÔNG được ghi nhận</strong> — tài khoản đã thi tại đơn vị này trước đó.</span><br>Chỉ Admin mới xem được kết quả lần đầu.';
      }
    } else {
      console.warn('Lưu server thất bại:', data.message);
      alert('Không lưu được kết quả lên server: ' + (data.message || 'Lỗi không xác định'));
    }
  } catch (err) {
    console.warn('Không gửi được lên server:', err);
    alert('Không kết nối được server. Kết quả có thể chưa được ghi nhận.');
  }
}

// ===== Nút khác =====
document.getElementById('new-test-btn').addEventListener('click', () => {
  document.getElementById('fullname').value = '';
  document.getElementById('rank').value = '';
  document.getElementById('team').value = '';
  showScreen('unit');
});
