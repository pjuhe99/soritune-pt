'use strict';

/**
 * 범용 시험 러너 — 오감각 / DISC 모두 같은 인터페이스.
 *   MeTestRunner.start(root, testType, member)
 *
 * 데이터 모듈(예: SensoryData)이 다음을 제공해야 함:
 *   .questions: [{type, text}, ...]
 *   .totalSteps: 5
 */
const MeTestRunner = {
  state: null,

  start(root, testType, member) {
    const data = this.dataFor(testType);
    if (!data) { root.innerHTML = '<div class="me-error">알 수 없는 시험입니다</div>'; return; }

    this.state = {
      root, testType, member, data,
      perStep: Math.ceil(data.questions.length / data.totalSteps),
      currentStep: 0,
      checked: new Set(),
    };
    this.render();
  },

  dataFor(testType) {
    if (testType === 'sensory') return window.SensoryData;
    return null;
  },

  render() {
    const s = this.state;
    s.root.innerHTML = `
      <div class="me-shell">
        <header class="me-header">
          <button class="me-btn me-btn-ghost" id="meBackBtn">← 메인으로</button>
          <div class="me-greet">${s.testType === 'sensory' ? '오감각 테스트' : '시험'}</div>
        </header>
        <main class="me-test">
          <div class="me-progress" id="meProgress"></div>
          <div class="me-step-header">
            <h2 id="meStepTitle"></h2>
            <span id="meStepDesc"></span>
          </div>
          <ul class="me-questions" id="meQuestions"></ul>
          <div class="me-test-actions">
            <button class="me-btn me-btn-ghost" id="meBtnPrev">이전</button>
            <button class="me-btn me-btn-primary" id="meBtnNext">다음</button>
          </div>
        </main>
      </div>
    `;
    document.getElementById('meBackBtn').onclick = () => MeApp.go('dashboard');
    document.getElementById('meBtnPrev').onclick = () => this.prev();
    document.getElementById('meBtnNext').onclick = () => this.next();
    this.renderStep();
  },

  renderStep() {
    const s = this.state;
    const start = s.currentStep * s.perStep;
    const end = Math.min(start + s.perStep, s.data.questions.length);
    const stepQs = s.data.questions.slice(start, end);

    document.getElementById('meStepTitle').textContent = `STEP ${s.currentStep + 1} / ${s.data.totalSteps}`;
    document.getElementById('meStepDesc').textContent =
      `${start + 1}~${end}번 문항 (총 ${s.data.questions.length}문항)`;

    const list = document.getElementById('meQuestions');
    list.innerHTML = stepQs.map((q, i) => {
      const idx = start + i;
      const checked = s.checked.has(idx);
      return `
        <li class="me-q ${checked ? 'me-q-checked' : ''}" data-idx="${idx}">
          <span class="me-q-mark"></span>
          <span class="me-q-text">${MeUI.esc(q.text)}</span>
        </li>
      `;
    }).join('');
    list.querySelectorAll('.me-q').forEach(li => {
      li.onclick = () => {
        const idx = Number(li.dataset.idx);
        if (s.checked.has(idx)) { s.checked.delete(idx); li.classList.remove('me-q-checked'); }
        else { s.checked.add(idx); li.classList.add('me-q-checked'); }
      };
    });

    const prog = document.getElementById('meProgress');
    prog.innerHTML = '';
    for (let i = 0; i < s.data.totalSteps; i++) {
      const dot = document.createElement('div');
      dot.className = 'me-progress-step' + (i < s.currentStep ? ' done' : i === s.currentStep ? ' active' : '');
      prog.appendChild(dot);
    }

    document.getElementById('meBtnPrev').style.visibility = s.currentStep === 0 ? 'hidden' : 'visible';
    document.getElementById('meBtnNext').textContent = s.currentStep === s.data.totalSteps - 1 ? '결과 보기' : '다음';
    window.scrollTo({ top: 0, behavior: 'smooth' });
  },

  prev() { if (this.state.currentStep > 0) { this.state.currentStep--; this.renderStep(); } },

  async next() {
    const s = this.state;
    if (s.currentStep < s.data.totalSteps - 1) {
      s.currentStep++;
      this.renderStep();
    } else {
      await this.submit();
    }
  },

  async submit() {
    const s = this.state;
    const answers = s.data.questions.map((_, i) => s.checked.has(i) ? 1 : 0);
    const btn = document.getElementById('meBtnNext');
    btn.disabled = true;
    btn.textContent = '저장 중...';
    const res = await MeAPI.post('/api/member_tests.php?action=submit', {
      test_type: s.testType,
      answers,
    });
    if (res.ok) {
      MeApp.go('result', { testType: s.testType, resultData: res.data.result_data });
    } else {
      btn.disabled = false;
      btn.textContent = '결과 보기';
      alert(res.message || '저장에 실패했습니다');
    }
  },
};
