(() => {
  const modal = document.querySelector("[data-attachment-modal]");

  if (!modal) {
    return;
  }

  const frame = modal.querySelector("[data-attachment-modal-frame]");
  const title = modal.querySelector("[data-attachment-modal-title]");
  const closeButton = modal.querySelector("[data-attachment-modal-close]");

  document.querySelectorAll("[data-attachment-preview]").forEach((button) => {
    button.addEventListener("click", () => {
      frame.src = button.dataset.attachmentPreview || "";
      title.textContent = button.dataset.attachmentName || "첨부파일 미리보기";
      modal.showModal();
    });
  });

  const closeModal = () => {
    modal.close();
    frame.src = "";
  };

  closeButton.addEventListener("click", closeModal);
  modal.addEventListener("click", (event) => {
    if (event.target === modal) {
      closeModal();
    }
  });
})();
