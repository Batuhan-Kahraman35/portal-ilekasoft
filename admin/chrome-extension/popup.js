/**
 * ÖRNEK Portal Extension - Popup Script
 * v1.4.0 - WhatsApp Mesaj & IT Talep
 */

// =============================================
// GLOBAL DEĞİŞKENLER
// =============================================
const API_BASE_URL = 'https://portal.ornekfirma.com/admin/api/it-talep.php';
let currentChatData = null;
let referenceData = null;
let whatsappKanalId = null; // WhatsApp kanalı ID'si (dinamik)

// =============================================
// DOM ELEMENTLERI
// =============================================

// WhatsApp Tab
const statusEl = document.getElementById('status');
const totalContactsEl = document.getElementById('totalContacts');
const sentCountEl = document.getElementById('sentCount');
const progressBarEl = document.getElementById('progressBar');
const startBtn = document.getElementById('startBtn');
const stopBtn = document.getElementById('stopBtn');
const portalBtn = document.getElementById('portalBtn');

// IT Talep Tab
const apiKeyEl = document.getElementById('apiKey');
const apiStatusEl = document.getElementById('apiStatus');
const saveApiKeyBtn = document.getElementById('saveApiKeyBtn');
const testApiBtn = document.getElementById('testApiBtn');
const loadingChatEl = document.getElementById('loadingChat');
const noChatEl = document.getElementById('noChat');
const chatInfoEl = document.getElementById('chatInfo');
const chatNameEl = document.getElementById('chatName');
const chatPhoneEl = document.getElementById('chatPhone');
const messagePreviewEl = document.getElementById('messagePreview');
const messageCountEl = document.getElementById('messageCount');
const selectedCountEl = document.getElementById('selectedCount');
const messageListEl = document.getElementById('messageList');
const selectAllMessagesBtn = document.getElementById('selectAllMessages');
const deselectAllMessagesBtn = document.getElementById('deselectAllMessages');
const talepFormEl = document.getElementById('talepForm');
const talepBaslikEl = document.getElementById('talepBaslik');
const talepKategoriEl = document.getElementById('talepKategori');
const talepAciliyetEl = document.getElementById('talepAciliyet');
const talepKaynakEl = document.getElementById('talepKaynak');
const talepDisAdEl = document.getElementById('talepDisAd');
const talepDisEmailEl = document.getElementById('talepDisEmail');
const talepDisTelefonEl = document.getElementById('talepDisTelefon');
const talepEkAciklamaEl = document.getElementById('talepEkAciklama');
const refreshChatBtn = document.getElementById('refreshChatBtn');
const createTalepBtn = document.getElementById('createTalepBtn');
const successMessageEl = document.getElementById('successMessage');
const errorMessageEl = document.getElementById('errorMessage');

// =============================================
// TAB NAVIGATION
// =============================================
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    // Tab butonlarını güncelle
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    // Tab içeriklerini güncelle
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
    
    // IT Talep sekmesi açıldığında verileri yükle
    if (btn.dataset.tab === 'it-talep') {
      // Önce API key'i yükle, sonra referans verileri al
      await loadSavedApiKey();
      await loadReferenceData();
      
      // Otomatik olarak sohbet bilgilerini almayı dene
      if (apiKeyEl.value.trim()) {
        getChatInfo();
      }
    }
  });
});

// =============================================
// WHATSAPP TAB FONKSİYONLARI
// =============================================

// Durum güncelle
async function updateStatus() {
  try {
    const data = await chrome.storage.local.get(['contacts', 'progress', 'status']);
    
    if (data.contacts) {
      totalContactsEl.textContent = data.contacts.length;
    }
    
    if (data.progress) {
      sentCountEl.textContent = data.progress.current || 0;
      progressBarEl.style.width = (data.progress.percentage || 0) + '%';
      progressBarEl.textContent = (data.progress.percentage || 0) + '%';
    }
    
    if (data.status) {
      statusEl.textContent = {
        'ready': 'Hazır',
        'sending': 'Gönderiliyor...',
        'completed': 'Tamamlandı',
        'stopped': 'Durduruldu'
      }[data.status] || 'Bilinmiyor';
    }
  } catch (error) {
    console.error('Durum güncelleme hatası:', error);
  }
}

// Başlat
startBtn.addEventListener('click', async () => {
  try {
    chrome.runtime.sendMessage({ action: 'startBulkSend' }, (response) => {
      if (chrome.runtime.lastError) {
        alert('❌ Hata: ' + chrome.runtime.lastError.message);
        return;
      }
      
      if (response && response.success) {
        statusEl.textContent = 'Gönderiliyor...';
        alert('✅ Gönderme başladı!\n\nHer kişi için yeni sekme açılacak.\nMesaj otomatik yazılacak, manuel olarak gönder butonuna basın.');
      }
    });
  } catch (error) {
    console.error('Başlatma hatası:', error);
    alert('❌ Hata: ' + error.message);
  }
});

// Durdur
stopBtn.addEventListener('click', async () => {
  try {
    chrome.runtime.sendMessage({ action: 'stopBulkSend' }, (response) => {
      if (response && response.success) {
        statusEl.textContent = 'Durduruldu';
        alert('⏸️ Gönderme durduruldu.');
      }
    });
  } catch (error) {
    console.error('Durdurma hatası:', error);
  }
});

// Portal'a git
portalBtn.addEventListener('click', () => {
  chrome.tabs.create({
    url: 'https://portal.ornekfirma.com/admin/pages/whatsapp-toplu-mesaj.php'
  });
});

// =============================================
// IT TALEP TAB FONKSİYONLARI
// =============================================

// Kayıtlı API key'i yükle
async function loadSavedApiKey() {
  try {
    const data = await chrome.storage.local.get(['itTalepApiKey']);
    if (data.itTalepApiKey) {
      apiKeyEl.value = data.itTalepApiKey;
      updateApiStatus(true);
    }
  } catch (error) {
    console.error('API key yükleme hatası:', error);
  }
}

// API key kaydet
saveApiKeyBtn.addEventListener('click', async () => {
  const apiKey = apiKeyEl.value.trim();
  if (!apiKey) {
    showError('Lütfen API key giriniz!');
    return;
  }
  
  try {
    await chrome.storage.local.set({ itTalepApiKey: apiKey });
    showSuccess('API key kaydedildi!');
    updateApiStatus(true);
  } catch (error) {
    showError('API key kaydedilemedi: ' + error.message);
  }
});

// API test et
testApiBtn.addEventListener('click', async () => {
  const apiKey = apiKeyEl.value.trim();
  if (!apiKey) {
    showError('Lütfen önce API key giriniz!');
    return;
  }
  
  testApiBtn.disabled = true;
  testApiBtn.textContent = '🔄 Test ediliyor...';
  
  try {
    const response = await fetch(API_BASE_URL + '?action=status', {
      method: 'GET',
      headers: {
        'Authorization': 'Bearer ' + apiKey,
        'Content-Type': 'application/json'
      }
    });
    
    const data = await response.json();
    
    if (data.success) {
      showSuccess('✅ API bağlantısı başarılı!');
      updateApiStatus(true);
      await chrome.storage.local.set({ itTalepApiKey: apiKey });
      loadReferenceData();
    } else {
      showError('API hatası: ' + data.message);
      updateApiStatus(false);
    }
  } catch (error) {
    showError('Bağlantı hatası: ' + error.message);
    updateApiStatus(false);
  } finally {
    testApiBtn.disabled = false;
    testApiBtn.textContent = '🔗 Test Et';
  }
});

// API durumunu güncelle
function updateApiStatus(connected) {
  if (connected) {
    apiStatusEl.textContent = 'Bağlı ✓';
    apiStatusEl.className = 'api-status connected';
  } else {
    apiStatusEl.textContent = 'Bağlı Değil';
    apiStatusEl.className = 'api-status disconnected';
  }
}

// Referans verileri yükle (kategoriler, aciliyetler)
async function loadReferenceData() {
  const apiKey = apiKeyEl.value.trim();
  if (!apiKey) {
    console.log('[IT Talep] API key boş, referans veriler yüklenmiyor');
    return;
  }
  
  console.log('[IT Talep] Referans veriler yükleniyor...');
  
  try {
    const response = await fetch(API_BASE_URL + '?action=references', {
      method: 'GET',
      headers: {
        'Authorization': 'Bearer ' + apiKey,
        'Content-Type': 'application/json'
      }
    });
    
    console.log('[IT Talep] API Response status:', response.status);
    const data = await response.json();
    console.log('[IT Talep] API Response data:', data);
    
    if (data.success) {
      referenceData = data.data;
      console.log('[IT Talep] Kategoriler:', referenceData.kategoriler);
      console.log('[IT Talep] Aciliyetler:', referenceData.aciliyetler);
      
      // WhatsApp kanalını bul
      if (referenceData.kanallar) {
        const whatsappKanal = referenceData.kanallar.find(k => 
          k.kanal_adi.toLowerCase().includes('whatsapp')
        );
        if (whatsappKanal) {
          whatsappKanalId = whatsappKanal.kanal_id;
          console.log('[IT Talep] WhatsApp kanal ID:', whatsappKanalId);
        } else {
          // WhatsApp yoksa ilk kanalı kullan
          whatsappKanalId = referenceData.kanallar[0]?.kanal_id || 1;
          console.log('[IT Talep] WhatsApp kanalı bulunamadı, varsayılan:', whatsappKanalId);
        }
      }
      
      // Kategorileri doldur
      if (referenceData.kategoriler && referenceData.kategoriler.length > 0) {
        talepKategoriEl.innerHTML = '<option value="">Seçiniz...</option>';
        referenceData.kategoriler.forEach(k => {
          talepKategoriEl.innerHTML += `<option value="${k.kategori_id}">${k.kategori_adi}</option>`;
        });
        console.log('[IT Talep] Kategoriler dropdown dolduruldu:', referenceData.kategoriler.length);
      } else {
        console.warn('[IT Talep] Kategori verisi boş!');
      }
      
      // Aciliyetleri doldur
      if (referenceData.aciliyetler && referenceData.aciliyetler.length > 0) {
        talepAciliyetEl.innerHTML = '<option value="">Seçiniz...</option>';
        referenceData.aciliyetler.forEach(a => {
          talepAciliyetEl.innerHTML += `<option value="${a.aciliyet_id}">${a.aciliyet_adi}</option>`;
        });
        console.log('[IT Talep] Aciliyetler dropdown dolduruldu:', referenceData.aciliyetler.length);
        
        // Varsayılan aciliyet: Normal (2)
        talepAciliyetEl.value = '2';
      } else {
        console.warn('[IT Talep] Aciliyet verisi boş!');
      }
      
      // Kaynak tiplerini doldur
      if (referenceData.kaynak_tipleri && referenceData.kaynak_tipleri.length > 0) {
        talepKaynakEl.innerHTML = '<option value="">Seçiniz...</option>';
        referenceData.kaynak_tipleri.forEach(k => {
          talepKaynakEl.innerHTML += `<option value="${k.id}">${k.adi}</option>`;
        });
        console.log('[IT Talep] Kaynak tipleri dropdown dolduruldu:', referenceData.kaynak_tipleri.length);
        
        // Varsayılan: Dış Kaynak (3) - WhatsApp'tan gelen genelde dış kaynak
        talepKaynakEl.value = '3';
      } else {
        console.warn('[IT Talep] Kaynak tipi verisi boş!');
      }
    } else {
      console.error('[IT Talep] API başarısız:', data.message);
    }
  } catch (error) {
    console.error('[IT Talep] Referans veriler yüklenemedi:', error);
  }
}

// Sohbet bilgilerini yenile
refreshChatBtn.addEventListener('click', () => {
  getChatInfo();
});

// WhatsApp'tan sohbet bilgilerini al
async function getChatInfo() {
  hideMessages();
  loadingChatEl.classList.remove('hidden');
  noChatEl.classList.add('hidden');
  chatInfoEl.classList.add('hidden');
  messagePreviewEl.classList.add('hidden');
  talepFormEl.classList.add('hidden');
  
  try {
    // Aktif WhatsApp Web sekmesini bul
    let tabs = await chrome.tabs.query({ url: 'https://web.whatsapp.com/*', active: true, currentWindow: true });
    
    if (tabs.length === 0) {
      // Aktif değilse, herhangi bir WhatsApp sekmesi ara
      const allTabs = await chrome.tabs.query({ url: 'https://web.whatsapp.com/*' });
      if (allTabs.length === 0) {
        loadingChatEl.classList.add('hidden');
        noChatEl.classList.remove('hidden');
        noChatEl.innerHTML = '❌ WhatsApp Web açık değil!<br><small>Önce <a href="https://web.whatsapp.com" target="_blank" style="color:#c62828">web.whatsapp.com</a> adresine gidin.</small>';
        return;
      }
      // İlk WhatsApp sekmesini kullan
      tabs = [allTabs[0]];
    }
    
    const tabId = tabs[0].id;
    console.log('[IT Talep] WhatsApp tab found:', tabId);
    
    // Önce content script'i inject etmeyi dene (zaten varsa hata vermez)
    try {
      await chrome.scripting.executeScript({
        target: { tabId: tabId },
        files: ['content.js']
      });
      console.log('[IT Talep] Content script injected/verified');
    } catch (injectError) {
      console.log('[IT Talep] Content script inject skipped:', injectError.message);
    }
    
    // Biraz bekle (content script'in yüklenmesi için)
    await new Promise(resolve => setTimeout(resolve, 500));
    
    // Content script'e mesaj gönder
    chrome.tabs.sendMessage(tabId, { action: 'getChatInfo' }, (response) => {
      loadingChatEl.classList.add('hidden');
      
      if (chrome.runtime.lastError) {
        console.error('İletişim hatası:', chrome.runtime.lastError);
        noChatEl.classList.remove('hidden');
        noChatEl.innerHTML = `❌ WhatsApp ile iletişim kurulamadı!<br>
          <small>
            <b>Çözüm:</b><br>
            1. WhatsApp Web sayfasını yenileyin (F5)<br>
            2. Bir kişiyle sohbet açın<br>
            3. Tekrar "Sohbet Bilgilerini Al" butonuna basın
          </small>`;
        return;
      }
      
      if (!response || !response.success) {
        noChatEl.classList.remove('hidden');
        noChatEl.innerHTML = response?.message || '❌ Sohbet bilgisi alınamadı!<br><small>Bir sohbet açın ve tekrar deneyin.</small>';
        return;
      }
      
      // Sohbet bilgilerini göster
      currentChatData = response.data;
      
      chatNameEl.textContent = currentChatData.name || 'Bilinmiyor';
      chatPhoneEl.textContent = currentChatData.phone || '-';
      chatInfoEl.classList.remove('hidden');
      
      // Mesajları göster (checkbox'lı)
      if (currentChatData.messages && currentChatData.messages.length > 0) {
        messageCountEl.textContent = currentChatData.messages.length;
        renderMessageList(currentChatData.messages);
        messagePreviewEl.classList.remove('hidden');
      }
      
      // Formu göster ve doldur
      talepDisAdEl.value = currentChatData.name || '';
      talepDisTelefonEl.value = currentChatData.phone || '';
      talepFormEl.classList.remove('hidden');
    });
    
  } catch (error) {
    loadingChatEl.classList.add('hidden');
    noChatEl.classList.remove('hidden');
    noChatEl.innerHTML = '❌ Hata: ' + error.message;
    console.error('getChatInfo error:', error);
  }
}

// =============================================
// MESAJ SEÇİM FONKSİYONLARI
// =============================================

/**
 * Mesaj listesini checkbox'lı olarak render et
 */
function renderMessageList(messages) {
  messageListEl.innerHTML = '';
  
  messages.forEach((msg, index) => {
    const messageItem = document.createElement('div');
    messageItem.className = 'message-item selected'; // Varsayılan olarak seçili
    messageItem.innerHTML = `
      <input type="checkbox" id="msg_${index}" data-index="${index}" checked>
      <div class="message-item-content">
        <div class="message-item-text">${escapeHtml(msg)}</div>
      </div>
    `;
    messageListEl.appendChild(messageItem);
    
    // Checkbox değişikliğini dinle
    const checkbox = messageItem.querySelector('input[type="checkbox"]');
    checkbox.addEventListener('change', () => {
      messageItem.classList.toggle('selected', checkbox.checked);
      updateSelectedCount();
    });
  });
  
  updateSelectedCount();
}

/**
 * Seçili mesaj sayısını güncelle
 */
function updateSelectedCount() {
  const checkboxes = messageListEl.querySelectorAll('input[type="checkbox"]');
  const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
  selectedCountEl.textContent = selectedCount;
}

/**
 * Seçili mesajları al
 */
function getSelectedMessages() {
  const selectedMessages = [];
  const checkboxes = messageListEl.querySelectorAll('input[type="checkbox"]:checked');
  
  checkboxes.forEach(cb => {
    const index = parseInt(cb.dataset.index);
    if (currentChatData?.messages && currentChatData.messages[index]) {
      selectedMessages.push(currentChatData.messages[index]);
    }
  });
  
  return selectedMessages;
}

/**
 * HTML escape
 */
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// Tümünü Seç butonu
selectAllMessagesBtn?.addEventListener('click', () => {
  const checkboxes = messageListEl.querySelectorAll('input[type="checkbox"]');
  checkboxes.forEach(cb => {
    cb.checked = true;
    cb.closest('.message-item').classList.add('selected');
  });
  updateSelectedCount();
});

// Hiçbirini Seçme butonu
deselectAllMessagesBtn?.addEventListener('click', () => {
  const checkboxes = messageListEl.querySelectorAll('input[type="checkbox"]');
  checkboxes.forEach(cb => {
    cb.checked = false;
    cb.closest('.message-item').classList.remove('selected');
  });
  updateSelectedCount();
});

// Talep oluştur
createTalepBtn.addEventListener('click', async () => {
  hideMessages();
  
  // Validasyon
  const baslik = talepBaslikEl.value.trim();
  const kategoriId = talepKategoriEl.value;
  const aciliyetId = talepAciliyetEl.value;
  const disAd = talepDisAdEl.value.trim();
  
  if (!baslik) {
    showError('Lütfen talep başlığı giriniz!');
    talepBaslikEl.focus();
    return;
  }
  
  if (!kategoriId) {
    showError('Lütfen kategori seçiniz!');
    talepKategoriEl.focus();
    return;
  }
  
  if (!aciliyetId) {
    showError('Lütfen aciliyet seçiniz!');
    talepAciliyetEl.focus();
    return;
  }
  
  const kaynakId = talepKaynakEl.value;
  if (!kaynakId) {
    showError('Lütfen talep kaynağı seçiniz!');
    talepKaynakEl.focus();
    return;
  }
  
  if (!disAd) {
    showError('Lütfen talep sahibi adını giriniz!');
    talepDisAdEl.focus();
    return;
  }
  
  const apiKey = apiKeyEl.value.trim();
  if (!apiKey) {
    showError('Lütfen önce API key ayarlayınız!');
    return;
  }
  
  // Açıklama oluştur
  let aciklama = '';
  
  // Seçili WhatsApp mesajlarını ekle
  const selectedMessages = getSelectedMessages();
  if (selectedMessages.length > 0) {
    aciklama = '📱 WhatsApp Yazışması (' + selectedMessages.length + ' mesaj):\n\n' + selectedMessages.join('\n\n---\n\n');
  }
  
  // Ek açıklama varsa ekle
  const ekAciklama = talepEkAciklamaEl.value.trim();
  if (ekAciklama) {
    if (aciklama) {
      aciklama += '\n\n---\n\n📝 Ek Not:\n' + ekAciklama;
    } else {
      aciklama = ekAciklama;
    }
  }
  
  // API'ye gönder
  createTalepBtn.disabled = true;
  createTalepBtn.textContent = '🔄 Gönderiliyor...';
  
  try {
    const kaynakTipiId = parseInt(talepKaynakEl.value) || 3;
    
    const payload = {
      action: 'create',
      baslik: baslik,
      aciklama: aciklama,
      kaynak_tipi: kaynakTipiId,
      dis_ad: disAd,
      dis_email: talepDisEmailEl.value.trim() || null,
      dis_telefon: talepDisTelefonEl.value.trim() || null,
      kanal_id: whatsappKanalId || 1, // Dinamik WhatsApp kanalı
      kategori_id: parseInt(kategoriId),
      aciliyet_id: parseInt(aciliyetId)
    };
    
    const response = await fetch(API_BASE_URL, {
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + apiKey,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    });
    
    const data = await response.json();
    
    if (data.success) {
      showSuccess(`✅ Talep oluşturuldu!\n\nTalep No: ${data.data.talep_no}\nTalep ID: ${data.data.talep_id}`);
      
      // Formu temizle
      talepBaslikEl.value = '';
      talepEkAciklamaEl.value = '';
      
    } else {
      showError('❌ Talep oluşturulamadı: ' + data.message);
    }
    
  } catch (error) {
    showError('❌ Bağlantı hatası: ' + error.message);
    console.error('Talep oluşturma hatası:', error);
  } finally {
    createTalepBtn.disabled = false;
    createTalepBtn.textContent = '✅ Talep Oluştur';
  }
});

// =============================================
// YARDIMCI FONKSİYONLAR
// =============================================

function showSuccess(message) {
  successMessageEl.textContent = message;
  successMessageEl.classList.remove('hidden');
  errorMessageEl.classList.add('hidden');
}

function showError(message) {
  errorMessageEl.textContent = message;
  errorMessageEl.classList.remove('hidden');
  successMessageEl.classList.add('hidden');
}

function hideMessages() {
  successMessageEl.classList.add('hidden');
  errorMessageEl.classList.add('hidden');
}

// =============================================
// BAŞLANGIÇ
// =============================================

// İlk yüklemede durumu göster
updateStatus();

// Her 2 saniyede bir WhatsApp durumunu güncelle
setInterval(updateStatus, 2000);
