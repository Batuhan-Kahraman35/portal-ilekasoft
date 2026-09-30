/**
 * ÖRNEK Portal Extension - Background Script
 * v1.4.0 - WhatsApp Mesaj & IT Talep
 */

let sending = false;
let currentIndex = 0;
let contacts = [];
let message = '';
let settings = {};

// Eklenti yüklendiğinde
chrome.runtime.onInstalled.addListener(() => {
  console.log('ÖRNEK Portal Extension v1.4.0 yüklendi!');
});

// Harici sitelerden (portal) mesaj dinle
chrome.runtime.onMessageExternal.addListener((request, sender, sendResponse) => {
  console.log('Portal\'dan mesaj alındı:', request);
  
  if (request.action === 'startSending') {
    // Veriyi storage'a kaydet
    chrome.storage.local.set({
      contacts: request.contacts,
      message: request.message,
      settings: request.settings,
      status: 'ready',
      timestamp: new Date().toISOString()
    }, () => {
      console.log('Veriler kaydedildi:', {
        contactCount: request.contacts.length,
        messageLength: request.message.length
      });
      
      // OTOMATIK GÖNDERIMI BAŞLAT!
      console.log('🚀 Otomatik gönderim başlatılıyor...');
      setTimeout(() => {
        startBulkSending();
      }, 2000); // 2 saniye bekle (kullanıcı bilgilendirilmesi için)
      
      sendResponse({ success: true, message: 'Veriler kaydedildi ve gönderim başlatıldı!' });
    });
    return true; // Asenkron yanıt için
  }
  
  sendResponse({ success: false, message: 'Geçersiz işlem' });
});

// Extension içinden mesaj dinle
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === 'getStatus') {
    chrome.storage.local.get(['status', 'progress', 'contacts'], (data) => {
      sendResponse(data);
    });
    return true;
  }
  
  if (request.action === 'startBulkSend') {
    startBulkSending();
    sendResponse({ success: true });
    return true;
  }
  
  if (request.action === 'stopBulkSend') {
    sending = false;
    chrome.storage.local.set({ status: 'stopped' });
    sendResponse({ success: true });
    return true;
  }
});

// Toplu gönderim başlat
async function startBulkSending() {
  if (sending) {
    console.log('Zaten gönderiliyor...');
    return;
  }
  
  // Verileri yükle
  chrome.storage.local.get(['contacts', 'message', 'settings'], async (data) => {
    if (!data.contacts || !data.message) {
      console.error('Veri bulunamadı!');
      return;
    }
    
    contacts = data.contacts;
    message = data.message;
    settings = data.settings || {
      delay_min: 15,
      delay_max: 30,
      frequency: 50,
      frequency_delay: 60
    };
    
    sending = true;
    currentIndex = 0;
    
    chrome.storage.local.set({ status: 'sending' });
    
    console.log(`${contacts.length} kişiye mesaj gönderilecek...`);
    
    // Önce mevcut WhatsApp Web sekmesini ara
    let whatsappTabId = null;
    
    try {
      const tabs = await chrome.tabs.query({ url: 'https://web.whatsapp.com/*' });
      if (tabs && tabs.length > 0) {
        whatsappTabId = tabs[0].id;
        console.log('Mevcut WhatsApp Web sekmesi bulundu:', whatsappTabId);
      } else {
        console.log('WhatsApp Web sekmesi bulunamadı, yeni sekme açılacak');
      }
    } catch (error) {
      console.error('Sekme arama hatası:', error);
    }
    
    for (let i = 0; i < contacts.length; i++) {
      if (!sending) break;
      
      const contact = contacts[i];
      const phone = '90' + contact.phone.replace(/\D/g, '');
      
      console.log(`${i + 1}/${contacts.length} - ${contact.name} (${contact.phone})`);
      
      // Mesaja zaman damgası ekle (spam önleme)
      let finalMessage = message;
      if (settings.add_timestamp) {
        const now = new Date();
        const timestamp = `[${now.toLocaleDateString('tr-TR')} ${now.toLocaleTimeString('tr-TR')}]`;
        finalMessage = `${message}\n\n${timestamp}`;
        console.log('⏰ Zaman damgası eklendi:', timestamp);
      }
      
      // WhatsApp Web URL'si (mesaj da parametre olarak)
      const chatUrl = `https://web.whatsapp.com/send?phone=${phone}&text=${encodeURIComponent(finalMessage)}`;
      
      if (!whatsappTabId) {
        // WhatsApp sekmesi yoksa yeni aç
        const tab = await chrome.tabs.create({ url: chatUrl, active: false });
        whatsappTabId = tab.id;
        console.log('Yeni WhatsApp sekmesi açıldı:', tab.id);
      } else {
        // Mevcut sekmeyi kullan
        await chrome.tabs.update(whatsappTabId, { url: chatUrl, active: i === 0 });
        console.log(i === 0 ? 'Mevcut sekme güncellendi (ilk mesaj)' : 'Sekme güncellendi');
      }
      
      // İlerlemeyi kaydet
      currentIndex = i + 1;
      chrome.storage.local.set({
        progress: {
          current: currentIndex,
          total: contacts.length,
          percentage: Math.round((currentIndex / contacts.length) * 100)
        }
      });
      
      // Mesajın gönderilmesi için bekle (content script çalışsın)
      const processingTime = 18000; // 18 saniye (8 saniye başlangıç + 10 saniye işlem)
      console.log(`${processingTime / 1000} saniye bekleniyor (mesaj gönderiliyor)...`);
      await sleep(processingTime);
      
      // Son değilse ekstra delay
      if (i < contacts.length - 1) {
        const delay = randomDelay(settings.delay_min * 1000, settings.delay_max * 1000);
        console.log(`${delay / 1000} saniye ek bekleme...`);
        await sleep(delay);
        
        if ((i + 1) % settings.frequency === 0) {
          console.log(`${settings.frequency} mesaj gönderildi, ${settings.frequency_delay} saniye uzun bekleme...`);
          await sleep(settings.frequency_delay * 1000);
        }
      }
    }
    
    sending = false;
    chrome.storage.local.set({ status: 'completed' });
    console.log('✅ Tamamlandı! Tüm mesajlar gönderildi.');
    
    // Tamamlandıktan sonra WhatsApp sekmesini kapat (opsiyonel - yorum satırından çıkar isterseniz)
    // if (whatsappTabId) {
    //   setTimeout(() => {
    //     chrome.tabs.remove(whatsappTabId);
    //     console.log('WhatsApp sekmesi kapatıldı');
    //   }, 3000);
    // }
  });
}

// Yardımcı fonksiyonlar
function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

function randomDelay(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}
