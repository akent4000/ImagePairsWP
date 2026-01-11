(function () {
    // === 1. Infinite Scroll for Grid (Classic) ===
    function initInfiniteScroll(wrap) {
      const sentinel = wrap.querySelector('.ip-scroll-sentinel');
      if (!sentinel) return;
  
      const instanceId = wrap.dataset.ipInstance;
      let isLoading = false;
      let isFinished = false;
  
      const observer = new IntersectionObserver(function (entries) {
        if (entries[0].isIntersecting && !isLoading && !isFinished) {
          loadNextPage();
        }
      }, { root: null, rootMargin: '0px 0px 400px 0px', threshold: 0 });
  
      observer.observe(sentinel);
  
      function loadNextPage() {
        isLoading = true;
        const currentPage = parseInt(wrap.dataset.page || 1);
        const nextPage = currentPage + 1;
        const atts = wrap.dataset.atts || '{}';
  
        const fd = new FormData();
        fd.append('action', 'ip_load_pairs');
        fd.append('nonce', ipPairs.nonce);
        fd.append('page', nextPage);
        fd.append('atts', atts);
        fd.append('instance', instanceId);
  
        fetch(ipPairs.ajaxUrl, { method: 'POST', body: fd })
          .then(res => res.json())
          .then(res => {
            if (res.success) {
              sentinel.insertAdjacentHTML('beforebegin', res.data.html);
              wrap.dataset.page = nextPage;
              if (!res.data.has_more) {
                isFinished = true;
                sentinel.remove();
                observer.disconnect();
              }
            } else {
              isFinished = true; 
              sentinel.remove();
            }
          })
          .catch(err => console.error(err))
          .finally(() => { isLoading = false; });
      }
    }
  
    // Запускаем скролл сразу, как только DOM готов
    document.addEventListener('DOMContentLoaded', function () {
      document.querySelectorAll('.ip-wrap').forEach(initInfiniteScroll);
      initLightbox(); // Запускаем лайтбокс только когда DOM готов
    });
  
    // === 2. Independent Lightbox Logic ===
    function initLightbox() {
        const overlay = document.querySelector('.ipbox-overlay');
        if (!overlay) return; // Если оверлея нет в HTML, выходим (но теперь мы уверены, что проверили вовремя)
      
        const imgEl = overlay.querySelector('.ipbox-img');
        const prevBtn = overlay.querySelector('.ipbox-prev');
        const nextBtn = overlay.querySelector('.ipbox-next');
        const closeBtn = overlay.querySelector('.ipbox-close');
        
        let currentInstanceId = null;
        let playlistIds = [];
        let currentPairIndex = -1;
        let currentImgNum = 1;
        const pairsCache = {};
      
        function getPairData(pairId) {
          if (pairsCache[pairId]) return pairsCache[pairId];
          const pairNode = document.querySelector(`.ip-pair[data-pair-id="${pairId}"]`);
          if (pairNode) {
             const data = { img1: null, img2: null };
             const link1 = pairNode.querySelector('a[data-img-index="1"]');
             const link2 = pairNode.querySelector('a[data-img-index="2"]');
             if(link1) data.img1 = { full: link1.getAttribute('href'), alt: link1.dataset.alt || '' };
             if(link2) data.img2 = { full: link2.getAttribute('href'), alt: link2.dataset.alt || '' };
            pairsCache[pairId] = data;
            return data;
          }
          return null; 
        }
      
        function openLightbox(anchor) {
          const instanceId = anchor.dataset.ipbox;
          const wrap = document.querySelector(`.ip-wrap[data-ip-instance="${instanceId}"]`);
          if (!wrap) return;
      
          try { playlistIds = JSON.parse(wrap.dataset.playlist || '[]'); } catch(e) { playlistIds = []; }
          if (playlistIds.length === 0) return;
      
          currentInstanceId = instanceId;
          const pairId = parseInt(anchor.dataset.pairId);
          currentImgNum = parseInt(anchor.dataset.imgIndex || 1);
          currentPairIndex = playlistIds.indexOf(pairId);
          if (currentPairIndex === -1) currentPairIndex = 0;
      
          getPairData(pairId); 
          showImage(pairId, currentImgNum);
          
          overlay.classList.add('is-open');
          document.body.classList.add('ipbox-open');
        }
      
        function closeLightbox() {
          overlay.classList.remove('is-open');
          document.body.classList.remove('ipbox-open');
          imgEl.src = '';
          currentInstanceId = null;
        }
      
        function showImage(pairId, imgNum) {
            const data = getPairData(pairId);
            if (data) {
                render(data, imgNum);
            } else {
                overlay.classList.add('is-loading');
                const fd = new FormData();
                fd.append('action', 'ip_get_lightbox_data');
                fd.append('nonce', ipPairs.nonce);
                fd.append('pair_id', pairId);
      
                fetch(ipPairs.ajaxUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success && res.data) {
                            pairsCache[pairId] = res.data; 
                            render(res.data, imgNum);
                        }
                    })
                    .catch(e => console.error(e))
                    .finally(() => overlay.classList.remove('is-loading'));
            }
        }
      
        function render(data, imgNum) {
            let target = (imgNum === 1) ? data.img1 : data.img2;
            if (!target && imgNum === 2 && data.img1) {
                target = data.img1;
                currentImgNum = 1;
            }
            if (target) {
                imgEl.src = target.full;
                imgEl.alt = target.alt || '';
            }
        }
      
        function navigate(dir) {
            if (!playlistIds.length) return;
            const currentPairId = playlistIds[currentPairIndex];
            const currentData = getPairData(currentPairId);
            
            if (dir === 1) { // NEXT
                if (currentImgNum === 1 && currentData && currentData.img2) {
                    currentImgNum = 2;
                    showImage(currentPairId, 2);
                    return;
                }
                const nextIdx = currentPairIndex + 1;
                if (nextIdx < playlistIds.length) {
                    currentPairIndex = nextIdx;
                    currentImgNum = 1;
                    showImage(playlistIds[nextIdx], 1);
                } else {
                    currentPairIndex = 0;
                    currentImgNum = 1;
                    showImage(playlistIds[0], 1);
                }
            } else { // PREV
                if (currentImgNum === 2) {
                    currentImgNum = 1;
                    showImage(currentPairId, 1);
                    return;
                }
                const prevIdx = currentPairIndex - 1;
                if (prevIdx >= 0) {
                    currentPairIndex = prevIdx;
                    const prevId = playlistIds[prevIdx];
                    const prevData = getPairData(prevId);
                    
                    if (prevData) {
                        currentImgNum = (prevData.img2) ? 2 : 1;
                        showImage(prevId, currentImgNum);
                    } else {
                        overlay.classList.add('is-loading');
                        const fd = new FormData();
                        fd.append('action', 'ip_get_lightbox_data');
                        fd.append('nonce', ipPairs.nonce);
                        fd.append('pair_id', prevId);
                        
                        fetch(ipPairs.ajaxUrl, { method: 'POST', body: fd })
                            .then(r=>r.json())
                            .then(res => {
                                if(res.success && res.data) {
                                    pairsCache[prevId] = res.data;
                                    currentImgNum = (res.data.img2) ? 2 : 1;
                                    render(res.data, currentImgNum);
                                }
                            })
                            .finally(() => overlay.classList.remove('is-loading'));
                    }
                } else {
                    const lastIdx = playlistIds.length - 1;
                    currentPairIndex = lastIdx;
                    // Auto select last of last
                    const lastId = playlistIds[lastIdx];
                    const lastData = getPairData(lastId);
                    if(lastData) {
                         currentImgNum = (lastData.img2) ? 2 : 1;
                         render(lastData, currentImgNum);
                    } else {
                        overlay.classList.add('is-loading');
                        const fd = new FormData();
                        fd.append('action', 'ip_get_lightbox_data');
                        fd.append('nonce', ipPairs.nonce);
                        fd.append('pair_id', lastId);
                        fetch(ipPairs.ajaxUrl, { method: 'POST', body: fd }).then(r=>r.json()).then(res=>{
                            if(res.success && res.data){
                                pairsCache[lastId] = res.data;
                                currentImgNum = (res.data.img2) ? 2 : 1;
                                render(res.data, currentImgNum);
                            }
                        }).finally(()=>overlay.classList.remove('is-loading'));
                    }
                }
            }
        }
      
        // Ипользуем делегирование событий на document
        document.addEventListener('click', function(e) {
          const link = e.target.closest('a.ip-zoom');
          if (link) {
            e.preventDefault(); // ВОТ ЭТО главное - отмена перехода по ссылке
            openLightbox(link);
          }
        });
      
        if (closeBtn) closeBtn.addEventListener('click', closeLightbox);
        if (nextBtn) nextBtn.addEventListener('click', () => navigate(1));
        if (prevBtn) prevBtn.addEventListener('click', () => navigate(-1));
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay || e.target === imgEl) closeLightbox();
        });
      
        document.addEventListener('keydown', (e) => {
            if (!overlay.classList.contains('is-open')) return;
            if (e.key === 'Escape') closeLightbox();
            if (e.key === 'ArrowRight') navigate(1);
            if (e.key === 'ArrowLeft') navigate(-1);
        });
    }
  
  })();