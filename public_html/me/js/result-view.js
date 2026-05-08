'use strict';

const SENSORY_CATEGORIES = {
  "0,0,0": {
    title: "균형형",
    subtitle: "어떤 감각도 아직 예민하지 않은 상태",
    content: "<b>특징:</b>\n<ul>\n<li>어떤 감각도 아직 예민하지 않은 상태로, 특정 감각이 두드러지지 않습니다</li>\n<li>한 가지 방식에 의존한 학습보다는 모든 감각을 고르게 활용하는 훈련이 필요합니다</li>\n<li>시각·청각·체각을 골고루 자극하는 복합적인 학습을 통해 감각을 깨워야 합니다</li>\n</ul>",
    learning: "<b>추천 학습법:</b>\n<ul>\n<li>시각·청각·체각을 골고루 이용하는 복합 학습법이 가장 효과적</li>\n<li>영상을 보면서(시각) + 소리를 듣고 따라 하면서(청각) + 몸으로 리듬을 느끼며(체각) 훈련</li>\n<li>한 가지 감각에만 의존하지 말고 다양한 방식을 번갈아 사용하세요</li>\n</ul>",
  },
  "0,0,1": {
    title: "체각형 우세 학습자",
    subtitle: "몸을 움직이며 학습하는 스타일",
    content: "<b>특징:</b>\n<ul>\n<li>움직이면서 배우는 스타일, 필기보다는 실습이 중요</li>\n<li>제스처, 롤플레잉, 몸으로 익히는 학습법이 효과적</li>\n<li>체험형 학습이 중요하며, 실전 상황에 익숙해져야 함</li>\n</ul>",
    learning: "<b>추천 학습법:</b>\n<ul>\n<li>실전 회화 & 롤플레잉 → 직접 대화하면서 문장 익히기</li>\n<li>몸으로 익히는 발음 트레이닝 → 손동작 활용, 입 모양 체크</li>\n<li>음성 따라 말하기 연습 → 실전적인 발음 & 억양 훈련</li>\n<li>훈련 시 박수를 치며 리듬을 몸으로 느끼며 훈련하면 효과적입니다</li>\n</ul>",
  },
  "0,1,0": {
    title: "시각형 우세 학습자",
    subtitle: "영상, 이미지 활용 학습이 효과적",
    content: "<b>특징:</b>\n<ul>\n<li>비주얼 자료가 학습에 중요한 역할</li>\n<li>영상을 보면서 배우거나, 정리하면서 학습하는 것이 효과적</li>\n<li>패턴을 분석하거나 시각적으로 기억하는 것이 유리함</li>\n<li>그동안 쉐도잉 방식의 영어 훈련을 시도했지만 효과를 보지 못했을 가능성이 있습니다 — 시각형은 소리만 듣고 따라 하는 방식이 잘 맞지 않을 수 있습니다</li>\n</ul>",
    learning: "<b>추천 학습법:</b>\n<ul>\n<li>영상 & 이미지 학습법 → 소리튜닝 훈련 시 입 모양 영상 분석</li>\n<li>마인드맵 & 정리 노트 활용 → 문장 구조를 시각적으로 정리</li>\n<li>패턴 분석 → 텍스트와 소리를 함께 학습</li>\n<li>소리튜닝을 할 때 반드시 1:1 코칭을 병행해야 효과적입니다</li>\n</ul>",
  },
  "0,1,1": {
    title: "시각 + 체각 혼합 학습자",
    subtitle: "눈으로 보고, 몸으로 익히는 스타일",
    content: "<b>특징:</b>\n<ul>\n<li>이미지를 보고 이해하고, 직접 경험하며 배우는 스타일</li>\n<li>정적인 필기보다는 손으로 정리하거나 실습하며 학습하는 것이 효과적</li>\n<li>제스처 & 영상 자료를 적극 활용하는 학습법이 적합</li>\n</ul>",
    learning: "<b>추천 학습법:</b>\n<ul>\n<li>영상 보면서 따라 하는 실습형 학습</li>\n<li>필기 & 정리하며 배우기 (손으로 직접 정리)</li>\n<li>제스처 활용한 말하기 연습</li>\n</ul>",
  },
  "1,0,0": {
    title: "청각형 우세 학습자",
    subtitle: "소리를 활용한 학습이 효과적",
    content: "<b>특징:</b>\n<ul>\n<li>듣기와 말하기 중심 학습이 효과적</li>\n<li>글을 읽는 것보다 소리를 듣고 따라 하는 방식이 적합</li>\n<li>반복 청취 & 쉐도잉이 핵심</li>\n<li>소리튜닝 학습 시 가장 빠르게 성장할 수 있는 유형입니다</li>\n</ul>",
    learning: "<b>추천 학습법:</b>\n<ul>\n<li>리스닝 집중 학습</li>\n<li>소리 분석 후 따라 말하기</li>\n<li>리듬 & 억양 중심 말하기 훈련</li>\n<li>소리블록만으로도 소리가 바뀔 수 있습니다 — 소리블록 훈련을 적극 활용하세요</li>\n</ul>",
  },
  "1,0,1": {
    title: "청각 + 체각 혼합 학습자",
    subtitle: "소리를 듣고 몸을 움직이며 학습하는 스타일",
    content: "<b>특징:</b>\n<ul>\n<li>소리를 듣고 따라 하면서 익히는 방식이 효과적</li>\n<li>필기보다는 몸으로 익히는 액션 기반 학습법이 적합</li>\n<li>리듬, 억양을 자연스럽게 익히는 학습이 필요</li>\n</ul>",
    learning: "<b>추천 학습법:</b>\n<ul>\n<li>리듬 & 박자 맞춰 말하기 연습</li>\n<li>제스처 활용 학습</li>\n<li>실제 대화 롤플레잉 훈련</li>\n</ul>",
  },
  "1,1,0": {
    title: "청각 + 시각 혼합 학습자",
    subtitle: "소리와 이미지를 결합한 학습법이 효과적",
    content: "<b>특징:</b>\n<ul>\n<li>듣기 + 시각적 자료 활용이 중요</li>\n<li>영상 & 오디오 학습법이 효과적</li>\n<li>글보다는 이미지 & 음성을 통한 학습이 적합</li>\n</ul>",
    learning: "<b>추천 학습법:</b>\n<ul>\n<li>영상 + 오디오 활용 학습</li>\n<li>소리튜닝 후 패턴 정리하여 분석</li>\n<li>쉐도잉 & 패턴 학습법 활용</li>\n</ul>",
  },
  "1,1,1": {
    title: "완전한 멀티 감각형 학습자",
    subtitle: "모든 감각이 골고루 발달 — 훈련 시 빠른 성장이 가능",
    content: "<b>특징:</b>\n<ul>\n<li>모든 감각이 골고루 발달되어 있어 어떤 학습 방식이든 잘 받아들일 수 있습니다</li>\n<li>훈련을 시작하면 다른 유형보다 빠르게 성장할 수 있는 잠재력이 있습니다</li>\n<li>다양한 감각을 동시에 활용하는 종합 훈련이 가장 효과적입니다</li>\n</ul>",
    learning: "<b>추천 학습법:</b>\n<ul>\n<li>다양한 감각을 동시에 활용하는 종합 훈련을 권장합니다</li>\n<li>영상 + 오디오 + 실전 연습을 병행하여 모든 감각을 자극하세요</li>\n<li>골고루 발달된 감각을 최대한 활용하면 빠른 성장이 가능합니다</li>\n</ul>",
  },
};

const MeResultView = {
  render(root, testType, resultData) {
    if (testType !== 'sensory') {
      root.innerHTML = '<div class="me-error">알 수 없는 결과입니다</div>';
      return;
    }
    const cat = SENSORY_CATEGORIES[resultData.key] || SENSORY_CATEGORIES["0,0,0"];
    const p = resultData.percents || { auditory: 0, visual: 0, kinesthetic: 0 };

    root.innerHTML = `
      <div class="me-shell">
        <header class="me-header">
          <button class="me-btn me-btn-ghost" id="meBackBtn">← 메인으로</button>
        </header>
        <main class="me-result">
          <div class="me-result-top">
            <div class="me-result-top-label">나의 감각 유형</div>
            <h2>${MeUI.esc(resultData.title || cat.title)}</h2>
            <p>${MeUI.esc(resultData.subtitle || cat.subtitle)}</p>
          </div>
          <div class="me-score">
            ${this.bar('청각', 'auditory', p.auditory || 0)}
            ${this.bar('시각', 'visual',   p.visual   || 0)}
            ${this.bar('체각', 'kinesthetic', p.kinesthetic || 0)}
          </div>
          <div class="me-divider"></div>
          <section class="me-info">
            <div class="me-info-label">특징</div>
            ${cat.content.replace(/<b>특징:<\/b>\n?/, '')}
          </section>
          <div class="me-divider"></div>
          <section class="me-info">
            <div class="me-info-label">추천 학습법</div>
            ${cat.learning.replace(/<b>추천 학습법:<\/b>\n?/, '')}
          </section>
          <div class="me-result-actions">
            <button class="me-btn me-btn-outline" id="meRetry">다시 하기</button>
            <button class="me-btn me-btn-primary" id="meBackDash">메인으로</button>
          </div>
        </main>
      </div>
    `;
    document.getElementById('meBackBtn').onclick = () => MeApp.go('dashboard');
    document.getElementById('meBackDash').onclick = () => MeApp.go('dashboard');
    document.getElementById('meRetry').onclick = () => MeApp.go('test', { testType });

    setTimeout(() => {
      document.querySelectorAll('.me-score-fill').forEach(el => {
        el.style.width = el.dataset.width;
      });
    }, 50);
  },

  bar(label, key, pct) {
    return `
      <div class="me-score-row">
        <span class="me-score-label">${label}</span>
        <div class="me-score-bar"><div class="me-score-fill me-score-${key}" data-width="${pct}%" style="width:0%"></div></div>
        <span class="me-score-pct">${pct}%</span>
      </div>
    `;
  },
};
