(function (global) {
  'use strict';

  const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
  const decoder = new TextDecoder('utf-8');

  function findEndOfCentralDirectory(view) {
    const minimum = Math.max(0, view.byteLength - 65557);
    for (let offset = view.byteLength - 22; offset >= minimum; offset--) {
      if (view.getUint32(offset, true) === 0x06054b50) return offset;
    }
    throw new Error('File không có cấu trúc DOCX hợp lệ.');
  }

  async function extractZipEntry(arrayBuffer, wantedName) {
    const view = new DataView(arrayBuffer);
    const eocd = findEndOfCentralDirectory(view);
    const entries = view.getUint16(eocd + 10, true);
    let offset = view.getUint32(eocd + 16, true);

    for (let index = 0; index < entries; index++) {
      if (view.getUint32(offset, true) !== 0x02014b50) break;
      const method = view.getUint16(offset + 10, true);
      const compressedSize = view.getUint32(offset + 20, true);
      const nameLength = view.getUint16(offset + 28, true);
      const extraLength = view.getUint16(offset + 30, true);
      const commentLength = view.getUint16(offset + 32, true);
      const localOffset = view.getUint32(offset + 42, true);
      const name = decoder.decode(new Uint8Array(arrayBuffer, offset + 46, nameLength));

      if (name === wantedName) {
        if (view.getUint32(localOffset, true) !== 0x04034b50) throw new Error('DOCX bị lỗi dữ liệu.');
        const localNameLength = view.getUint16(localOffset + 26, true);
        const localExtraLength = view.getUint16(localOffset + 28, true);
        const dataOffset = localOffset + 30 + localNameLength + localExtraLength;
        const compressed = new Uint8Array(arrayBuffer.slice(dataOffset, dataOffset + compressedSize));
        if (method === 0) return decoder.decode(compressed);
        if (method !== 8 || typeof DecompressionStream === 'undefined') {
          throw new Error('Trình duyệt chưa hỗ trợ giải nén DOCX. Vui lòng dùng Chrome hoặc Edge mới nhất.');
        }
        const stream = new Blob([compressed]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
        return decoder.decode(await new Response(stream).arrayBuffer());
      }
      offset += 46 + nameLength + extraLength + commentLength;
    }
    throw new Error('Không tìm thấy nội dung Word trong file.');
  }

  function isRed(color) {
    const hex = (color || '').replace('#', '').toUpperCase();
    if (!/^[0-9A-F]{6}$/.test(hex)) return false;
    const r = parseInt(hex.slice(0, 2), 16);
    const g = parseInt(hex.slice(2, 4), 16);
    const b = parseInt(hex.slice(4, 6), 16);
    return r >= 180 && g <= 80 && b <= 80;
  }

  function paragraphData(paragraph) {
    const text = Array.from(paragraph.getElementsByTagNameNS(WORD_NS, 't'))
      .map(node => node.textContent || '').join('').replace(/\s+/g, ' ').trim();
    const red = Array.from(paragraph.getElementsByTagNameNS(WORD_NS, 'color'))
      .some(node => isRed(node.getAttributeNS(WORD_NS, 'val') || node.getAttribute('w:val') || node.getAttribute('val')));
    return { text, red };
  }

  function parseDocumentXml(xmlText) {
    const xml = new DOMParser().parseFromString(xmlText, 'application/xml');
    if (xml.querySelector('parsererror')) throw new Error('Không đọc được nội dung XML của file Word.');
    const paragraphs = Array.from(xml.getElementsByTagNameNS(WORD_NS, 'p')).map(paragraphData);
    const rawQuestions = [];
    let current = null;

    paragraphs.forEach(({ text, red }) => {
      if (!text) return;
      const questionMatch = text.match(/^Câu\s*(\d+)\s*[.\-:)]\s*(.+)$/i);
      if (questionMatch) {
        current = { sourceNumber: Number(questionMatch[1]), q: questionMatch[2].trim(), options: [], correct: [] };
        rawQuestions.push(current);
        return;
      }
      const optionMatch = text.match(/^([A-F])\s*[.\-:)]\s*(.+)$/i);
      if (current && optionMatch) {
        const optionIndex = current.options.length;
        current.options.push(optionMatch[2].trim());
        if (red) current.correct.push(optionIndex);
        return;
      }
      if (current && current.options.length) {
        current.options[current.options.length - 1] += ` ${text}`;
      } else if (current) {
        current.q += ` ${text}`;
      }
    });

    const questions = [];
    const errors = [];
    rawQuestions.forEach(item => {
      if (item.options.length < 2) errors.push(`Câu ${item.sourceNumber}: thiếu phương án trả lời.`);
      else if (item.correct.length !== 1) errors.push(`Câu ${item.sourceNumber}: cần đúng 1 đáp án tô đỏ (đang có ${item.correct.length}).`);
      else questions.push({ q: item.q, options: item.options, answer: item.correct[0], sourceNumber: item.sourceNumber });
    });
    return { questions, errors, detected: rawQuestions.length };
  }

  async function parseDocx(file) {
    if (!file || !/\.docx$/i.test(file.name)) {
      throw new Error('Định dạng .doc cũ chưa thể đọc trực tiếp. Hãy mở file trong Word, chọn “Save As” và lưu thành .docx.');
    }
    const xml = await extractZipEntry(await file.arrayBuffer(), 'word/document.xml');
    return parseDocumentXml(xml);
  }

  global.ExamImporter = { parseDocx, parseDocumentXml, extractZipEntry };
})(typeof window !== 'undefined' ? window : globalThis);
