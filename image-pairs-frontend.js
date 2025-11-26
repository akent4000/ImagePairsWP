(function () {
  function initInfiniteScroll(wrap) {
    const sentinel = wrap.querySelector('.ip-scroll-sentinel');
    if (!sentinel) return;

    let loading = false;
    let hasMore = true;

    const perPage = wrap.dataset.perPage || '20';
    const atts = wrap.dataset.atts || '{}';
    const instance = wrap.dataset.ipInstance || '';

    const observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        if (loading || !hasMore) return;

        loading = true;

        const currentPage = parseInt(wrap.dataset.page || '1', 10);
        const nextPage = currentPage + 1;

        const formData = new FormData();
        formData.append('action', 'ip_load_pairs');
        formData.append('nonce', ipPairs.nonce);
        formData.append('page', nextPage);
        formData.append('per_page', perPage);
        formData.append('atts', atts);
        formData.append('instance', instance);

        fetch(ipPairs.ajaxUrl, {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
        })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            if (!data || !data.success) {
              loading = false;
              return;
            }

            if (data.data && data.data.html) {
              // Вставляем новые пары ПЕРЕД маячком
              sentinel.insertAdjacentHTML('beforebegin', data.data.html);
            }

            if (data.data && data.data.has_more) {
              wrap.dataset.page = data.data.next_page || nextPage;
              loading = false;
            } else {
              hasMore = false;
              observer.unobserve(sentinel);
            }
          })
          .catch(function () {
            // В случае ошибки просто перестаём пытаться
            loading = false;
          });
      });
    }, {
      root: null,
      rootMargin: '0px 0px 500px 0px', // подгружаем, когда до низа остаётся ~300px
      threshold: 0
    });

    observer.observe(sentinel);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.ip-wrap').forEach(initInfiniteScroll);
  });
})();
