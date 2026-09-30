/**
 * ORNEK WhatsApp Bulk Sender - Content Script
 * Version: 1.3.6 - Double Send & Single Message Fix
 */

console.log('[ORNEK] ========================================');
console.log('[ORNEK] Extension loaded v1.3.6');
console.log('[ORNEK] URL:', window.location.href);
console.log('[ORNEK] ========================================');

// URL değişikliklerini dinle
let lastProcessedUrl = '';
let lastProcessedTime = 0; // Son işlem zamanı

function checkAndSendMessage() {
  const currentUrl = window.location.href;
  const now = Date.now();
  
  // Aynı URL'i tekrar işleme
  if (currentUrl === lastProcessedUrl) {
    console.log('[ORNEK] Bu URL zaten işlendi, atlanıyor');
    return;
  }
  
  // 5 saniye içinde tekrar çağrıldıysa atla (çift tetikleme önleme)
  if (now - lastProcessedTime < 5000) {
    console.log('[ORNEK] Çok kısa sürede tekrar çağrıldı, atlanıyor');
    return;
  }
  
  const url = new URL(currentUrl);
  const phone = url.searchParams.get('phone');
  const text = url.searchParams.get('text');

  console.log('[ORNEK] Phone param:', phone);
  console.log('[ORNEK] Text param:', text);

  if (!phone || !text) {
    console.log('[ORNEK] No params, normal WhatsApp usage');
  } else {
    console.log('[ORNEK] Message params found! Auto-send will start in 8 seconds...');
    lastProcessedUrl = currentUrl; // İşlem yapılacak, URL'i kaydet
    lastProcessedTime = now; // İşlem zamanını kaydet
    
    setTimeout(function() {
      console.log('[ORNEK] *** STARTING AUTO-SEND ***');
      autoSend(text);
    }, 8000);
  }
}

// İlk yüklemede kontrol et
checkAndSendMessage();

// URL değişikliklerini izle (pushState/popState ile)
const originalPushState = history.pushState;
history.pushState = function() {
  originalPushState.apply(this, arguments);
  console.log('[ORNEK] URL changed (pushState)');
  setTimeout(checkAndSendMessage, 1000);
};

window.addEventListener('popstate', function() {
  console.log('[ORNEK] URL changed (popstate)');
  setTimeout(checkAndSendMessage, 1000);
});

// MutationObserver ile URL değişikliklerini izle (WhatsApp Web için)
let lastUrl = location.href;
let mutationCheckTimeout = null;

new MutationObserver(() => {
  const currentUrl = location.href;
  if (currentUrl !== lastUrl && currentUrl.includes('phone=')) {
    lastUrl = currentUrl;
    console.log('[ORNEK] URL changed (mutation):', currentUrl);
    
    // Debounce: Birden fazla mutation aynı anda gelirse sadece son çağrıyı yap
    if (mutationCheckTimeout) {
      clearTimeout(mutationCheckTimeout);
    }
    
    mutationCheckTimeout = setTimeout(() => {
      checkAndSendMessage();
      mutationCheckTimeout = null;
    }, 2000); // 2 saniye bekle (çoklu tetiklemeyi önle)
  }
}).observe(document, { subtree: true, childList: true });

  function autoSend(message) {
    console.log('[ORNEK] Looking for input box...');
    
    let attempts = 0;
    const maxAttempts = 40;
    
    const checker = setInterval(function() {
      attempts++;
      console.log('[ORNEK] Input box attempt ' + attempts + '/' + maxAttempts);
      
      const box = document.querySelector('div[contenteditable="true"][data-tab="10"]');
      
      if (box) {
        clearInterval(checker);
        console.log('[ORNEK] Input box FOUND!');
        
        box.focus();
        box.click();
        
        console.log('[ORNEK] Typing message...');
        box.textContent = message;
        box.dispatchEvent(new InputEvent('input', { bubbles: true }));
        
        console.log('[ORNEK] Message typed successfully');
        
        setTimeout(function() {
          console.log('[ORNEK] Looking for send button...');
          clickSendButton();
        }, 2000);
        
      } else if (attempts >= maxAttempts) {
        clearInterval(checker);
        console.error('[ORNEK] Input box NOT FOUND after 40 attempts');
      }
    }, 500);
  }

  function clickSendButton() {
    let attempts = 0;
    const maxAttempts = 30; // 20'den 30'a çıkarıldı
    
    const checker = setInterval(function() {
      attempts++;
      console.log('[ORNEK] Send button attempt ' + attempts + '/' + maxAttempts);
      
      const selectors = [
        'button[data-tab="11"]',
        'button[aria-label*="Send"]',
        'button[aria-label*="Gönder"]',
        'button[aria-label*="send"]',
        'span[data-icon="send"]',
        'button span[data-icon="send"]',
        '[data-testid="send"]'
      ];
      
      let btn = null;
      for (let i = 0; i < selectors.length; i++) {
        const element = document.querySelector(selectors[i]);
        if (element) {
          btn = (element.tagName === 'BUTTON') ? element : element.closest('button');
          if (btn) {
            console.log('[ORNEK] Found with selector: ' + selectors[i]);
            break;
          }
        }
      }
      
      if (btn && !btn.disabled) {
        clearInterval(checker);
        console.log('[ORNEK] Send button FOUND!');
        console.log('[ORNEK] Button:', btn);
        console.log('[ORNEK] Clicking send button...');
        
        btn.focus();
        
        const mouseDown = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
        const mouseUp = new MouseEvent('mouseup', { bubbles: true, cancelable: true });
        const click = new MouseEvent('click', { bubbles: true, cancelable: true });
        
        btn.dispatchEvent(mouseDown);
        btn.dispatchEvent(mouseUp);
        btn.dispatchEvent(click);
        btn.click();
        
        console.log('[ORNEK] BUTTON CLICKED! (Enter key devre dışı - çift gönderim önleme)');
        
        // Enter tuşu KALDIRILDI - çift gönderim sorunu çözüldü
        
      } else if (attempts === 5) {
        console.log('[ORNEK] Trying ENTER key (early attempt)...');
        tryEnterKey();
        
      } else if (attempts === 15) {
        console.log('[ORNEK] Trying ENTER key (mid attempt)...');
        tryEnterKey();
        
      } else if (attempts >= maxAttempts) {
        clearInterval(checker);
        console.error('[ORNEK] Send button NOT FOUND after all attempts');
        console.log('[ORNEK] Trying ENTER key as final attempt...');
        tryEnterKey();
      }
    }, 500);
  }
  
  function tryEnterKey() {
    console.log('[ORNEK] tryEnterKey() called');
    
    const inputBox = document.querySelector('div[contenteditable="true"][data-tab="10"]');
    if (inputBox) {
      console.log('[ORNEK] Input box found for Enter key');
      inputBox.focus();
      
      // Önce input eventini tetikle (mesajın orada olduğundan emin ol)
      inputBox.dispatchEvent(new InputEvent('input', { bubbles: true }));
      
      // Biraz bekle
      setTimeout(function() {
        console.log('[ORNEK] Dispatching Enter key events...');
        
        const keyDown = new KeyboardEvent('keydown', {
          key: 'Enter',
          code: 'Enter',
          keyCode: 13,
          which: 13,
          bubbles: true,
          cancelable: true
        });
        
        const keyPress = new KeyboardEvent('keypress', {
          key: 'Enter',
          code: 'Enter',
          keyCode: 13,
          which: 13,
          bubbles: true,
          cancelable: true
        });
        
        const keyUp = new KeyboardEvent('keyup', {
          key: 'Enter',
          code: 'Enter',
          keyCode: 13,
          which: 13,
          bubbles: true,
          cancelable: true
        });
        
        inputBox.dispatchEvent(keyDown);
        inputBox.dispatchEvent(keyPress);
        inputBox.dispatchEvent(keyUp);
        
        console.log('[ORNEK] Enter key sequence COMPLETE');
        
        // EKSTRA GÜVENLİK: 1 kez daha Enter dene (çift gönderim riski azaltıldı)
        setTimeout(function() {
          inputBox.dispatchEvent(keyDown);
          inputBox.dispatchEvent(keyUp);
          console.log('[ORNEK] Enter retry 1/1 (final)');
        }, 500);
        
      }, 100);
      
    } else {
      console.error('[ORNEK] Input box not found for Enter key!');
    }
  }

// =============================================
// IT TALEP - SOHBET BİLGİSİ ALMA
// =============================================

/**
 * Popup'tan gelen mesajları dinle
 */
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  console.log('[ORNEK] Message received:', request.action);
  
  if (request.action === 'getChatInfo') {
    const chatInfo = extractChatInfo();
    sendResponse(chatInfo);
    return true;
  }
  
  return false;
});

/**
 * Aktif sohbetten bilgileri çıkar
 */
function extractChatInfo() {
  try {
    console.log('[ORNEK] Extracting chat info...');
    console.log('[ORNEK] Current URL:', window.location.href);
    
    // Sohbet başlığını al (kişi adı) - Güncel WhatsApp Web selector'ları
    const headerSelectors = [
      // Yeni WhatsApp Web yapısı (2024-2026)
      '#main header span[title]:not([title=""])',
      'div[data-testid="conversation-info-header-chat-title"]',
      'div[data-testid="conversation-header"] span[title]',
      '#main header div[role="button"] span[dir="auto"]',
      'header._amid span[title]',
      'header span[dir="auto"][title]',
      '#main header span[dir="auto"]',
      // Eski yapılar (fallback)
      'header span[data-testid="conversation-info-header-chat-title"]',
      '#main header span[title]',
      // En genel selector
      '#main header span'
    ];
    
    let chatName = null;
    let headerElement = null;
    
    // Debug: Tüm header span'larını listele
    const allHeaderSpans = document.querySelectorAll('#main header span');
    console.log('[ORNEK] All header spans found:', allHeaderSpans.length);
    allHeaderSpans.forEach((span, i) => {
      if (span.textContent && span.textContent.trim().length > 0) {
        console.log(`[ORNEK] Span ${i}:`, span.textContent.trim().substring(0, 50), '| title:', span.getAttribute('title'));
      }
    });
    
    for (const selector of headerSelectors) {
      const elements = document.querySelectorAll(selector);
      console.log(`[ORNEK] Selector "${selector}" found:`, elements.length);
      
      for (const el of elements) {
        const title = el.getAttribute('title');
        const text = el.textContent?.trim();
        
        // Boş veya çok kısa değerleri atla
        if (title && title.length > 1) {
          chatName = title;
          headerElement = el;
          console.log('[ORNEK] Chat name found from title:', chatName);
          break;
        } else if (text && text.length > 1 && !text.includes('message') && !text.includes('click')) {
          chatName = text;
          headerElement = el;
          console.log('[ORNEK] Chat name found from text:', chatName);
          break;
        }
      }
      
      if (chatName) break;
    }
    
    if (!chatName) {
      // Son çare: #main içinde herhangi bir isim bul
      const mainPanel = document.querySelector('#main');
      if (!mainPanel) {
        console.log('[ORNEK] #main panel not found - no chat open');
        return { 
          success: false, 
          message: '❌ Aktif sohbet bulunamadı!<br><small>Bir kişiyle sohbet açın.</small>' 
        };
      }
      
      console.log('[ORNEK] #main found but no chat name extracted');
      return { 
        success: false, 
        message: '❌ Sohbet bilgisi alınamadı!<br><small>WhatsApp Web\'i yenileyin ve tekrar deneyin.</small>' 
      };
    }
    
    // Telefon numarasını al (varsa)
    let phone = extractPhoneNumber(chatName);
    
    // Mesajları al
    const messages = extractMessages();
    
    console.log('[ORNEK] Chat info extracted:', { chatName, phone, messageCount: messages.length });
    
    return {
      success: true,
      data: {
        name: chatName,
        phone: phone,
        messages: messages
      }
    };
    
  } catch (error) {
    console.error('[ORNEK] Error extracting chat info:', error);
    return { 
      success: false, 
      message: '❌ Hata: ' + error.message 
    };
  }
}

/**
 * Telefon numarasını çıkar - birden fazla kaynaktan dene
 */
function extractPhoneNumber(chatName) {
  console.log('[ORNEK] Extracting phone number...');
  
  // 1. Önce sohbet başlığından (chatName) dene
  if (chatName) {
    const phoneFromName = parsePhoneFromText(chatName);
    if (phoneFromName) {
      console.log('[ORNEK] Phone found in chat name:', phoneFromName);
      return phoneFromName;
    }
  }
  
  // 2. URL'den dene (send?phone=xxx formatı veya /c/xxx)
  const urlPhoneMatch = window.location.href.match(/phone=(\d+)/);
  if (urlPhoneMatch) {
    console.log('[ORNEK] Phone found in URL param:', urlPhoneMatch[1]);
    return urlPhoneMatch[1];
  }
  
  // URL'de /c/ parametresi (contact ID genelde telefon)
  const urlCMatch = window.location.href.match(/\/c\/(\d+)/);
  if (urlCMatch && urlCMatch[1].length >= 10) {
    console.log('[ORNEK] Phone found in URL /c/:', urlCMatch[1]);
    return urlCMatch[1];
  }
  
  // 3. data-id attribute'undan (message-list div'inde chat id var)
  const chatIdSelectors = [
    'div[data-id]', // Mesaj ID'leri formatında olabilir
    '#main header', // Header'ın kendisi
    'div[data-testid="conversation-panel-wrapper"]'
  ];
  
  // Chat ID pattern: true_905XXXXXXXXXX@c.us_XXXXX
  const chatIdElements = document.querySelectorAll('div[data-id*="@"]');
  for (const el of chatIdElements) {
    const dataId = el.getAttribute('data-id');
    if (dataId) {
      const match = dataId.match(/(\d{10,13})@/);
      if (match) {
        console.log('[ORNEK] Phone found in data-id:', match[1]);
        return match[1];
      }
    }
  }
  
  // 4. Sohbet header'ındaki subtitle'dan ("son görülme" veya telefon no)
  const subtitleSelectors = [
    '#main header span[title]:nth-child(2)',
    'header div[data-testid="conversation-info-header"] span:nth-child(2)',
    '#main header div > div > span:last-child',
    'header span[dir="auto"]:not([title])',
    '#main header div span',
    '#main header span',
    '[data-testid="conversation-header"] span'
  ];
  
  for (const selector of subtitleSelectors) {
    const elements = document.querySelectorAll(selector);
    for (const el of elements) {
      const text = el.textContent?.trim() || el.getAttribute('title');
      if (text) {
        const phone = parsePhoneFromText(text);
        if (phone) {
          console.log('[ORNEK] Phone found in header subtitle:', phone);
          return phone;
        }
      }
    }
  }
  
  // 5. Profil panelinden dene (açıksa)
  const profileSelectors = [
    '[data-testid="contact-info-subtitles"] span',
    'div[data-testid="drawer-right"] span[dir="auto"]',
    'section div span[dir="auto"]',
    '[data-testid="phone-number"]',
    '[data-testid="about-phone"]'
  ];
  
  for (const selector of profileSelectors) {
    const el = document.querySelector(selector);
    if (el) {
      const phone = parsePhoneFromText(el.textContent);
      if (phone) {
        console.log('[ORNEK] Phone found in profile panel:', phone);
        return phone;
      }
    }
  }
  
  // 6. Mesajlardan telefon numarası bulmayı dene (son çare)
  const allSpans = document.querySelectorAll('#main span');
  for (const span of allSpans) {
    const text = span.textContent;
    if (text && text.length < 20) {
      const phone = parsePhoneFromText(text);
      if (phone && phone.length >= 10) {
        console.log('[ORNEK] Phone found in span element:', phone);
        return phone;
      }
    }
  }
  
  console.log('[ORNEK] No phone number found after all attempts');
  return null;
}

/**
 * Metinden telefon numarası parse et
 */
function parsePhoneFromText(text) {
  if (!text) return null;
  
  // +90 5XX XXX XX XX veya benzeri formatları ara
  const phonePatterns = [
    /\+?(90)?\s*5\d{2}\s*\d{3}\s*\d{2}\s*\d{2}/,  // Türk mobil
    /\+?(90)?\s*[2-4]\d{2}\s*\d{3}\s*\d{2}\s*\d{2}/, // Türk sabit
    /\+?\d{10,13}/, // Genel uluslararası
  ];
  
  for (const pattern of phonePatterns) {
    const match = text.match(pattern);
    if (match) {
      // Sadece rakamları al
      return match[0].replace(/\D/g, '');
    }
  }
  
  // Son çare: metindeki tüm rakamları al
  const digits = text.replace(/\D/g, '');
  if (digits.length >= 10 && digits.length <= 13) {
    return digits;
  }
  
  return null;
}

/**
 * Sohbetteki mesajları çıkar
 */
function extractMessages() {
  const messages = [];
  
  try {
    // Mesaj container'ını bul
    const messageContainerSelectors = [
      'div[data-testid="conversation-panel-messages"]',
      '#main div.copyable-area',
      '#main [role="application"]'
    ];
    
    let container = null;
    for (const selector of messageContainerSelectors) {
      container = document.querySelector(selector);
      if (container) break;
    }
    
    if (!container) {
      console.log('[ORNEK] Message container not found');
      return messages;
    }
    
    // Mesaj elementlerini bul
    const messageSelectors = [
      'div[data-testid="msg-container"]',
      'div.message-in, div.message-out',
      'div[class*="message"]'
    ];
    
    let messageElements = [];
    for (const selector of messageSelectors) {
      messageElements = container.querySelectorAll(selector);
      if (messageElements.length > 0) {
        console.log('[ORNEK] Found messages with selector:', selector, 'count:', messageElements.length);
        break;
      }
    }
    
    // Son 20 mesajı al (çok fazla mesaj performansı etkiler)
    const maxMessages = 20;
    const startIdx = Math.max(0, messageElements.length - maxMessages);
    
    for (let i = startIdx; i < messageElements.length; i++) {
      const msgEl = messageElements[i];
      
      // Mesaj metnini bul
      const textSelectors = [
        'span.selectable-text',
        'span[data-testid="msg-text"]',
        'div.copyable-text span',
        'span.copyable-text'
      ];
      
      let msgText = null;
      for (const selector of textSelectors) {
        const textEl = msgEl.querySelector(selector);
        if (textEl && textEl.textContent) {
          msgText = textEl.textContent.trim();
          break;
        }
      }
      
      if (!msgText) continue;
      
      // Gönderen bilgisini al (gelen mi giden mi)
      const isOutgoing = msgEl.classList.contains('message-out') || 
                         msgEl.querySelector('[data-testid="msg-meta"]')?.closest('[data-testid*="out"]') !== null;
      
      // Zaman bilgisini al
      let time = '';
      const timeEl = msgEl.querySelector('span[data-testid="msg-meta"]') || 
                     msgEl.querySelector('div[data-testid="msg-time"]');
      if (timeEl) {
        time = timeEl.textContent.trim();
      }
      
      // Mesaj formatla
      const prefix = isOutgoing ? '➡️ Ben' : '⬅️ Karşı taraf';
      const formattedMsg = `${prefix} (${time || 'N/A'}):\n${msgText}`;
      messages.push(formattedMsg);
    }
    
    console.log('[ORNEK] Extracted messages count:', messages.length);
    
  } catch (error) {
    console.error('[ORNEK] Error extracting messages:', error);
  }
  
  return messages;
}
