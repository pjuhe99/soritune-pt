'use strict';

/**
 * Voice Intake 사전 설문 러너 — 2섹션 폼.
 *
 * 사용:
 *   MeVoiceIntakeRunner.start(rootEl, member);
 *   MeVoiceIntakeRunner.renderResult(rootEl, resultData);
 *
 * 응답 모델:
 *   state.values[questionId] = string (single) | string[] (multi)
 *   state.others[questionId] = string ('기타' 인 경우 자유 입력)
 *   state.section = 1 | 2 ('thanks' 는 제출 후 감사 화면)
 */
const MeVoiceIntakeRunner = {
  state: null,

  start(root, member) {
    if (!window.VoiceIntakeData) {
      root.innerHTML = '<div class="me-error">설문 데이터가 로드되지 않았습니다</div>';
      return;
    }
    this.state = {
      root, member,
      data: window.VoiceIntakeData,
      values: {},   // qId → string | string[]
      others: {},   // qId → string
      section: 1,
    };
    this.render();
  },

  /** 제출 후 감사 화면 (start() 와 별개로 호출) */
  renderThanks(root) {
    root.innerHTML = `
      <div class="me-shell">
        <main class="me-result">
          <div class="me-result-top">
            <div class="me-result-top-label">응답 완료</div>
            <h2>✓ 응답이 저장되었습니다</h2>
            <p>소중한 응답 감사합니다. 코치 매칭에 반영됩니다.</p>
          </div>
          <div class="me-result-actions">
            <button class="me-btn me-btn-primary" id="meBackDash">메인으로</button>
          </div>
        </main>
      </div>
    `;
    document.getElementById('meBackDash').onclick = () => MeApp.go('dashboard');
  },

  /** "내 응답 보기" — 저장된 result_data 를 11문항 펼친 화면으로 표시 */
  renderResult(root, resultData) {
    const qs = (window.VoiceIntakeData && window.VoiceIntakeData.questions) || [];
    const answers = (resultData && resultData.answers) || {};
    const rows = qs.map(q => {
      const a = answers[q.id] || {};
      let answerHtml;
      if (q.type === 'multi') {
        const vs = Array.isArray(a.values) ? a.values : [];
        answerHtml = vs.map(v => `<div>• ${MeUI.esc(v)}</div>`).join('');
      } else {
        const v = typeof a.value === 'string' ? a.value : '';
        if (v === '기타' && typeof a.other === 'string' && a.other) {
          answerHtml = MeUI.esc(a.other);
        } else {
          answerHtml = MeUI.esc(v || '-');
        }
      }
      return `
        <section class="me-vi-row">
          <div class="me-vi-q">${MeUI.esc(q.id.toUpperCase())}. ${MeUI.esc(q.text)}</div>
          <div class="me-vi-a">${answerHtml}</div>
        </section>
      `;
    }).join('');

    root.innerHTML = `
      <div class="me-shell">
        <header class="me-header">
          <button class="me-btn me-btn-ghost" id="meBackBtn">← 메인으로</button>
          <div class="me-greet">음성 케어 매칭 사전 질문</div>
        </header>
        <main class="me-vi-result">${rows}</main>
        <div class="me-result-actions">
          <button class="me-btn me-btn-outline" id="meRetry">수정하려면 다시 응답</button>
          <button class="me-btn me-btn-primary" id="meBackDash">메인으로</button>
        </div>
      </div>
    `;
    document.getElementById('meBackBtn').onclick = () => MeApp.go('dashboard');
    document.getElementById('meBackDash').onclick = () => MeApp.go('dashboard');
    document.getElementById('meRetry').onclick = () => MeApp.go('test', { testType: 'voice_intake' });
  },

  render() {
    const s = this.state;
    const sectionQs = s.data.questions.filter(q => q.section === s.section);
    const sectionTitle = s.section === 1
      ? `1 / 2  기본 정보`
      : `2 / 2  훈련 방향 설정을 위한 설문입니다.`;

    s.root.innerHTML = `
      <div class="me-shell">
        <header class="me-header">
          <button class="me-btn me-btn-ghost" id="meBackBtn">← 메인으로</button>
          <div class="me-greet">음성 케어 매칭 사전 질문</div>
        </header>
        <main class="me-vi">
          <div class="me-step-header">
            <h2>${sectionTitle}</h2>
          </div>
          <ol class="me-vi-questions">
            ${sectionQs.map(q => this.renderQuestion(q)).join('')}
          </ol>
          <div class="me-test-actions">
            ${s.section === 1
              ? `<button class="me-btn me-btn-ghost" id="meBackDash">취소</button>`
              : `<button class="me-btn me-btn-ghost" id="meBtnPrev">← 이전</button>`}
            <button class="me-btn me-btn-primary" id="meBtnNext">${s.section === 1 ? '다음 →' : '제출하기'}</button>
          </div>
        </main>
      </div>
    `;

    document.getElementById('meBackBtn').onclick = () => MeApp.go('dashboard');
    if (s.section === 1) {
      document.getElementById('meBackDash').onclick = () => MeApp.go('dashboard');
    } else {
      document.getElementById('meBtnPrev').onclick = () => { s.section = 1; this.render(); };
    }
    document.getElementById('meBtnNext').onclick = () => this.next();

    this.bindInputs();
    this.updateNextEnabled();
  },

  renderQuestion(q) {
    const s = this.state;
    if (q.type === 'multi') {
      const checked = new Set(s.values[q.id] || []);
      return `
        <li class="me-vi-q-item" data-qid="${q.id}">
          <div class="me-vi-q-text">${MeUI.esc(q.text)}</div>
          <div class="me-vi-options">
            ${q.options.map((opt, i) => `
              <label class="me-vi-checkbox">
                <input type="checkbox" data-qid="${q.id}" data-opt="${MeUI.esc(opt)}" ${checked.has(opt) ? 'checked' : ''}>
                <span>${MeUI.esc(opt)}</span>
              </label>
            `).join('')}
          </div>
          <div class="me-vi-error" data-error="${q.id}"></div>
        </li>
      `;
    }
    // single
    const value = s.values[q.id] || '';
    const otherVisible = q.allow_other && value === '기타';
    return `
      <li class="me-vi-q-item" data-qid="${q.id}">
        <div class="me-vi-q-text">${MeUI.esc(q.text)}</div>
        <div class="me-vi-options">
          ${q.options.map((opt, i) => `
            <label class="me-vi-radio">
              <input type="radio" name="vi_${q.id}" data-qid="${q.id}" data-opt="${MeUI.esc(opt)}" ${value === opt ? 'checked' : ''}>
              <span>${MeUI.esc(opt)}</span>
            </label>
          `).join('')}
        </div>
        ${q.allow_other ? `
          <input type="text" class="me-input me-vi-other" data-qid="${q.id}"
            placeholder="기타 내용을 입력해주세요 (200자 이내)"
            maxlength="200"
            value="${MeUI.esc(s.others[q.id] || '')}"
            style="${otherVisible ? '' : 'display:none'}">
        ` : ''}
        <div class="me-vi-error" data-error="${q.id}"></div>
      </li>
    `;
  },

  bindInputs() {
    const s = this.state;
    s.root.querySelectorAll('input[type="radio"]').forEach(r => {
      r.onchange = () => {
        const qid = r.dataset.qid;
        const opt = r.dataset.opt;
        s.values[qid] = opt;
        // toggle "기타" textarea visibility
        const otherInput = s.root.querySelector(`input.me-vi-other[data-qid="${qid}"]`);
        if (otherInput) {
          otherInput.style.display = opt === '기타' ? '' : 'none';
          if (opt !== '기타') delete s.others[qid];
        }
        this.clearError(qid);
        this.updateNextEnabled();
      };
    });
    s.root.querySelectorAll('input[type="checkbox"]').forEach(c => {
      c.onchange = () => {
        const qid = c.dataset.qid;
        const opt = c.dataset.opt;
        const arr = s.values[qid] || [];
        if (c.checked) {
          if (!arr.includes(opt)) arr.push(opt);
        } else {
          const idx = arr.indexOf(opt);
          if (idx >= 0) arr.splice(idx, 1);
        }
        // Q10 상호 배타: '해당없음'
        if (qid === 'q10') {
          if (opt === '해당없음' && c.checked) {
            // 다른 모두 해제
            s.values.q10 = ['해당없음'];
          } else if (opt !== '해당없음' && c.checked) {
            // '해당없음' 해제
            const noneIdx = arr.indexOf('해당없음');
            if (noneIdx >= 0) arr.splice(noneIdx, 1);
            s.values.q10 = arr;
          } else {
            s.values.q10 = arr;
          }
          // re-render to reflect mutual exclusion
          this.render();
          return;
        }
        s.values[qid] = arr;
        this.clearError(qid);
        this.updateNextEnabled();
      };
    });
    s.root.querySelectorAll('input.me-vi-other').forEach(t => {
      t.oninput = () => {
        const qid = t.dataset.qid;
        s.others[qid] = t.value;
        this.clearError(qid);
        this.updateNextEnabled();
      };
    });
  },

  showError(qid, msg) {
    const el = this.state.root.querySelector(`[data-error="${qid}"]`);
    if (el) { el.textContent = msg; el.classList.add('me-vi-error-shown'); }
  },

  clearError(qid) {
    const el = this.state.root.querySelector(`[data-error="${qid}"]`);
    if (el) { el.textContent = ''; el.classList.remove('me-vi-error-shown'); }
  },

  validateSection(section) {
    const s = this.state;
    const qs = s.data.questions.filter(q => q.section === section);
    let firstError = null;
    for (const q of qs) {
      if (q.type === 'single') {
        const v = s.values[q.id];
        if (!v) {
          this.showError(q.id, '선택해주세요');
          if (!firstError) firstError = q.id;
          continue;
        }
        if (q.allow_other && v === '기타') {
          const other = (s.others[q.id] || '').trim();
          if (!other) {
            this.showError(q.id, '내용을 입력해주세요');
            if (!firstError) firstError = q.id;
          }
        }
      } else {
        const arr = s.values[q.id] || [];
        if (arr.length === 0) {
          this.showError(q.id, '한 개 이상 선택해주세요');
          if (!firstError) firstError = q.id;
        }
      }
    }
    return firstError === null;
  },

  isSectionComplete(section) {
    const s = this.state;
    const qs = s.data.questions.filter(q => q.section === section);
    for (const q of qs) {
      if (q.type === 'single') {
        const v = s.values[q.id];
        if (!v) return false;
        if (q.allow_other && v === '기타') {
          const other = (s.others[q.id] || '').trim();
          if (!other) return false;
        }
      } else {
        const arr = s.values[q.id] || [];
        if (arr.length === 0) return false;
      }
    }
    return true;
  },

  updateNextEnabled() {
    const s = this.state;
    const btn = document.getElementById('meBtnNext');
    if (btn) btn.disabled = !this.isSectionComplete(s.section);
  },

  next() {
    const s = this.state;
    if (!this.validateSection(s.section)) return;

    if (s.section === 1) {
      s.section = 2;
      this.render();
    } else {
      this.submit();
    }
  },

  async submit() {
    const s = this.state;
    if (!this.validateSection(1) || !this.validateSection(2)) return;

    const answersOut = {};
    for (const q of s.data.questions) {
      if (q.type === 'multi') {
        answersOut[q.id] = { values: s.values[q.id] || [] };
      } else {
        const entry = { value: s.values[q.id] };
        if (q.allow_other && s.values[q.id] === '기타') {
          entry.other = (s.others[q.id] || '').trim();
        }
        answersOut[q.id] = entry;
      }
    }

    const btn = document.getElementById('meBtnNext');
    if (btn) { btn.disabled = true; btn.textContent = '저장 중...'; }
    const res = await MeAPI.post('/api/member_tests.php?action=submit', {
      test_type: 'voice_intake',
      answers: answersOut,
    });
    if (res.ok) {
      this.renderThanks(s.root);
    } else {
      if (btn) { btn.disabled = false; btn.textContent = '제출하기'; }
      alert(res.message || '저장에 실패했습니다');
    }
  },
};
