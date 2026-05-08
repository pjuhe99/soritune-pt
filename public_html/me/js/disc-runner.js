'use strict';

/**
 * DISC 시험 러너 — 10문항 × 4단어 순위 입력.
 *
 * 사용:
 *   MeDiscRunner.start(rootEl, member);
 *
 * 응답 모델:
 *   state.ranks[i] = { D: rank|null, I: rank|null, S: rank|null, C: rank|null }
 *   각 i (0..9) 에서 4 ranks = {1, 2, 3, 4} 의 순열이어야 완료
 *
 * UI:
 *   "가장 잘 어울리는 단어는?" → 클릭 → 4점
 *   "두 번째로 잘 어울리는 단어는?" → 클릭 → 3점
 *   "세 번째로 잘 어울리는 단어는?" → 클릭 → 2점, 마지막 단어 = 1점 자동
 *   X 버튼: 그 단어의 rank만 null (재할당 시 next missing rank in 4→3→2→1 order)
 */
const MeDiscRunner = {
  state: null,

  start(root, member) {
    if (!window.DiscData) {
      root.innerHTML = '<div class="me-error">DISC 데이터가 로드되지 않았습니다</div>';
      return;
    }
    this.state = {
      root, member,
      data: window.DiscData,
      currentStep: 0,
      ranks: window.DiscData.questions.map(() => ({ D: null, I: null, S: null, C: null })),
    };
    this.render();
  },

  render() {
    const s = this.state;
    s.root.innerHTML = `
      <div class="me-shell">
        <header class="me-header">
          <button class="me-btn me-btn-ghost" id="meBackBtn">← 메인으로</button>
          <div class="me-greet">DISC 진단</div>
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
    const q = s.data.questions[s.currentStep];
    const r = s.ranks[s.currentStep];
    const filledRanks = ['D','I','S','C'].map(t => r[t]).filter(v => v !== null);
    const filledCount = filledRanks.length;
    const isComplete = filledCount === 4;

    document.getElementById('meStepTitle').textContent = `${s.currentStep + 1} / 10`;
    let desc;
    if (isComplete) {
      desc = '✓ 모두 매겼습니다';
    } else {
      const promptByCount = ['가장 잘 어울리는 단어는?', '두 번째로 잘 어울리는 단어는?', '세 번째로 잘 어울리는 단어는?', '마지막 단어를 선택해주세요'];
      desc = promptByCount[filledCount];
    }
    document.getElementById('meStepDesc').textContent = desc;

    const list = document.getElementById('meQuestions');
    list.innerHTML = ['D','I','S','C'].map(t => {
      const word = q[t];
      const rank = r[t];
      const ranked = rank !== null;
      return `
        <li class="me-q me-q-disc ${ranked ? 'me-q-checked' : ''}" data-type="${t}">
          <span class="me-q-rank">${ranked ? rank + '점' : ''}</span>
          <span class="me-q-text">${MeUI.esc(word)}</span>
          ${ranked ? `<button class="me-q-x" data-type="${t}" aria-label="해제">×</button>` : ''}
        </li>
      `;
    }).join('');

    list.querySelectorAll('.me-q').forEach(li => {
      li.onclick = (e) => {
        if (e.target.classList.contains('me-q-x')) {
          this.unrank(li.dataset.type);
        } else {
          this.assignNext(li.dataset.type);
        }
      };
    });

    const prog = document.getElementById('meProgress');
    prog.innerHTML = '';
    for (let i = 0; i < s.data.totalSteps; i++) {
      const dot = document.createElement('div');
      const allRanked = ['D','I','S','C'].every(t => s.ranks[i][t] !== null);
      dot.className = 'me-progress-step' + (i < s.currentStep && allRanked ? ' done' : i === s.currentStep ? ' active' : '');
      prog.appendChild(dot);
    }

    document.getElementById('meBtnPrev').style.visibility = s.currentStep === 0 ? 'hidden' : 'visible';
    const btnNext = document.getElementById('meBtnNext');
    btnNext.disabled = !isComplete;
    btnNext.textContent = s.currentStep === s.data.totalSteps - 1 ? '결과 보기' : '다음';

    window.scrollTo({ top: 0, behavior: 'smooth' });
  },

  assignNext(type) {
    const s = this.state;
    const r = s.ranks[s.currentStep];
    if (r[type] !== null) return;

    const filled = ['D','I','S','C'].map(t => r[t]).filter(v => v !== null);
    const next = [4,3,2,1].find(rk => !filled.includes(rk));
    if (next === undefined) return;
    r[type] = next;

    // Auto-1: this click made 3 ranks of {4,3,2}? Then last unranked → 1
    if (filled.length + 1 === 3) {
      const newFilled = [...filled, next];
      if (newFilled.includes(2) && newFilled.includes(3) && newFilled.includes(4)) {
        const lastUnranked = ['D','I','S','C'].find(t => r[t] === null);
        if (lastUnranked) r[lastUnranked] = 1;
      }
    }

    this.renderStep();
  },

  unrank(type) {
    const s = this.state;
    const r = s.ranks[s.currentStep];
    r[type] = null;
    this.renderStep();
  },

  prev() {
    if (this.state.currentStep > 0) {
      this.state.currentStep--;
      this.renderStep();
    }
  },

  async next() {
    const s = this.state;
    const r = s.ranks[s.currentStep];
    const isComplete = ['D','I','S','C'].every(t => r[t] !== null);
    if (!isComplete) return;

    if (s.currentStep < s.data.totalSteps - 1) {
      s.currentStep++;
      this.renderStep();
    } else {
      await this.submit();
    }
  },

  async submit() {
    const s = this.state;
    const answers = s.ranks.map(r => [r.D, r.I, r.S, r.C]);
    const btn = document.getElementById('meBtnNext');
    btn.disabled = true;
    btn.textContent = '저장 중...';
    const res = await MeAPI.post('/api/member_tests.php?action=submit', {
      test_type: 'disc',
      answers,
    });
    if (res.ok) {
      MeApp.go('result', { testType: 'disc', resultData: res.data.result_data });
    } else {
      btn.disabled = false;
      btn.textContent = '결과 보기';
      alert(res.message || '저장에 실패했습니다');
    }
  },
};
